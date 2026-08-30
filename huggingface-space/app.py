"""
The build server behind the Space.

One job: run Gradle on the bundled Android project and stream what it says
back to the browser. Deliberately small — there is one build at a time, no
queue, no database, and the log lives in memory plus a file on disk.

Two things it does take seriously, because they are what makes a build server
useful rather than frustrating:

1. **The log is streamed, not returned at the end.** An Android build takes
   minutes, and a page that shows nothing until it finishes is indistinguishable
   from one that has hung.
2. **A failure is reported as a failure**, with the exit code and the tail of
   the output, rather than a generic error. The whole point of the log pane is
   that a build which breaks can be diagnosed from it.
"""

from __future__ import annotations

import asyncio
import json
import os
import re
import shutil
import signal
import subprocess
import time
from collections import deque
from dataclasses import dataclass, field
from pathlib import Path
from typing import Deque, Optional

from fastapi import FastAPI, HTTPException
from fastapi.responses import (
    FileResponse,
    HTMLResponse,
    JSONResponse,
    StreamingResponse,
)
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel

APP_DIR = Path(__file__).resolve().parent
ANDROID_DIR = APP_DIR / "android"
STATIC_DIR = APP_DIR / "static"
OUTPUT_DIR = APP_DIR / "output"
LOG_FILE = OUTPUT_DIR / "build.log"

OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

# Enough scrollback to cover a whole Gradle build without the browser holding
# an unbounded string.
MAX_LOG_LINES = 4000

# A build that has produced no output for this long is stuck rather than slow;
# Gradle prints progress continuously, so silence is the signal.
STALL_TIMEOUT_SECONDS = 900

# Hard ceiling, so a wedged build cannot hold the Space forever.
MAX_BUILD_SECONDS = 3600


@dataclass
class BuildState:
    """Everything about the current or last build."""

    status: str = "idle"  # idle | running | success | failed | cancelled
    variant: str = "debug"
    started_at: float = 0.0
    finished_at: float = 0.0
    exit_code: Optional[int] = None
    apk_path: Optional[str] = None
    apk_size: int = 0
    message: str = ""
    lines: Deque[str] = field(default_factory=lambda: deque(maxlen=MAX_LOG_LINES))
    # Monotonic counter so a reconnecting client can ask for "everything after
    # line N" rather than re-reading the whole log.
    cursor: int = 0

    @property
    def duration(self) -> float:
        if not self.started_at:
            return 0.0
        end = self.finished_at or time.time()
        return round(end - self.started_at, 1)


STATE = BuildState()
PROCESS: Optional[subprocess.Popen] = None
BUILD_LOCK = asyncio.Lock()

app = FastAPI(title="Animeh APK Builder", docs_url=None, redoc_url=None)


def log(line: str) -> None:
    """Append one line to the in-memory log and the log file."""
    STATE.lines.append(line)
    STATE.cursor += 1
    try:
        with LOG_FILE.open("a", encoding="utf-8") as handle:
            handle.write(line + "\n")
    except OSError:
        # A full disk must not take the build down; the in-memory log is still
        # being streamed to the browser.
        pass


def environment_report() -> dict:
    """What is actually installed, read from the machine rather than assumed."""

    def run(cmd: list[str], pattern: str) -> str:
        """First line matching `pattern`, not simply the first line.

        `java -version` writes to stderr and may be preceded by JVM notices;
        `gradle --version` opens with a row of dashes. Taking line zero picks
        up whichever of those happens to come first.
        """
        try:
            result = subprocess.run(
                cmd, capture_output=True, text=True, timeout=30, check=False
            )
        except (OSError, subprocess.SubprocessError):
            return "not found"

        combined = f"{result.stdout}\n{result.stderr}"
        for line in combined.splitlines():
            line = line.strip()
            if line and re.search(pattern, line, re.IGNORECASE):
                return line

        return "not found"

    sdk = os.environ.get("ANDROID_HOME", "")
    platforms = []
    build_tools = []

    if sdk:
        platforms_dir = Path(sdk) / "platforms"
        build_tools_dir = Path(sdk) / "build-tools"
        if platforms_dir.is_dir():
            platforms = sorted(p.name for p in platforms_dir.iterdir() if p.is_dir())
        if build_tools_dir.is_dir():
            build_tools = sorted(p.name for p in build_tools_dir.iterdir() if p.is_dir())

    gradlew = ANDROID_DIR / "gradlew"

    return {
        "java": run(["java", "-version"], r"version"),
        "gradle": run(["gradle", "--version"], r"^Gradle\s"),
        "sdk_root": sdk or "unset",
        "platforms": platforms,
        "build_tools": build_tools,
        "project": str(ANDROID_DIR),
        "project_present": (ANDROID_DIR / "settings.gradle.kts").is_file(),
        "wrapper_present": gradlew.is_file(),
        "disk_free_mb": shutil.disk_usage(str(APP_DIR)).free // (1024 * 1024),
    }


class BuildRequest(BaseModel):
    variant: str = "debug"
    api_base: str = ""
    clean: bool = False


def write_local_properties(api_base: str) -> None:
    """
    Point Gradle at the SDK, and at the backend when one was given.

    `local.properties` is where `app/build.gradle.kts` reads `ANIMEH_API_BASE`
    from, so this is how the Space sets the APK's default server address
    without editing the build file.
    """
    lines = [f"sdk.dir={os.environ.get('ANDROID_HOME', '/opt/android-sdk')}"]

    api_base = api_base.strip()
    if api_base:
        # Only a plain https URL: this value is written into a properties file
        # that Gradle evaluates, so a newline in it would inject another
        # property entirely.
        if not re.fullmatch(r"https?://[A-Za-z0-9._~:/?#\[\]@!$&'()*+,;=%-]+", api_base):
            raise HTTPException(status_code=400, detail="Geçersiz sunucu adresi.")
        lines.append(f"ANIMEH_API_BASE={api_base}")

    (ANDROID_DIR / "local.properties").write_text("\n".join(lines) + "\n", encoding="utf-8")


def find_apk(variant: str) -> Optional[Path]:
    """The newest APK Gradle produced for this variant."""
    apk_dir = ANDROID_DIR / "app" / "build" / "outputs" / "apk" / variant
    if not apk_dir.is_dir():
        return None

    candidates = sorted(apk_dir.rglob("*.apk"), key=lambda p: p.stat().st_mtime, reverse=True)
    return candidates[0] if candidates else None


async def run_build(variant: str, api_base: str, clean: bool) -> None:
    """Run Gradle, streaming its output into the log."""
    global PROCESS

    STATE.status = "running"
    STATE.variant = variant
    STATE.started_at = time.time()
    STATE.finished_at = 0.0
    STATE.exit_code = None
    STATE.apk_path = None
    STATE.apk_size = 0
    STATE.message = ""
    STATE.lines.clear()
    STATE.cursor = 0

    try:
        LOG_FILE.unlink(missing_ok=True)
    except OSError:
        pass

    env = environment_report()

    log("═══ Animeh APK Builder ═══")
    log(f"Varyant       : {variant}")
    log(f"Java          : {env['java']}")
    log(f"Gradle        : {env['gradle']}")
    log(f"SDK           : {env['sdk_root']}")
    log(f"Platformlar   : {', '.join(env['platforms']) or 'yok'}")
    log(f"Build tools   : {', '.join(env['build_tools']) or 'yok'}")
    log(f"Boş disk      : {env['disk_free_mb']} MB")
    log(f"Proje         : {env['project']}")
    log("")

    if not env["project_present"]:
        STATE.status = "failed"
        STATE.finished_at = time.time()
        STATE.message = "android/ klasörü bulunamadı — Space'e yüklenmemiş olabilir."
        log("HATA: " + STATE.message)
        return

    try:
        write_local_properties(api_base)
    except HTTPException as error:
        STATE.status = "failed"
        STATE.finished_at = time.time()
        STATE.message = str(error.detail)
        log("HATA: " + STATE.message)
        return

    task = "assembleDebug" if variant == "debug" else "assembleRelease"
    tasks = (["clean"] if clean else []) + [task]

    gradlew = ANDROID_DIR / "gradlew"
    launcher = [str(gradlew)] if gradlew.is_file() else ["gradle"]

    command = launcher + [
        *tasks,
        "--no-daemon",
        # The daemon would survive between builds, but a Space container is
        # ephemeral and a lingering daemon just holds memory.
        "--console=plain",
        "--stacktrace",
    ]

    log("$ " + " ".join(command))
    log("")

    PROCESS = subprocess.Popen(
        command,
        cwd=str(ANDROID_DIR),
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        bufsize=1,
        env={**os.environ, "TERM": "dumb"},
        # Its own process group, so cancelling kills Gradle's children too
        # rather than orphaning a compiler.
        start_new_session=True,
    )

    loop = asyncio.get_running_loop()
    last_output = time.time()

    def mark_output() -> None:
        nonlocal last_output
        last_output = time.time()

    reader = loop.run_in_executor(None, read_lines_sync, PROCESS, loop, mark_output)

    while True:
        if PROCESS.poll() is not None:
            break

        now = time.time()
        if now - last_output > STALL_TIMEOUT_SECONDS:
            log("")
            log(f"HATA: {STALL_TIMEOUT_SECONDS} saniyedir çıktı yok — derleme durduruldu.")
            terminate_process()
            STATE.message = "Derleme takıldı (çıktı gelmiyor)."
            break

        if now - STATE.started_at > MAX_BUILD_SECONDS:
            log("")
            log("HATA: Derleme süre sınırını aştı — durduruldu.")
            terminate_process()
            STATE.message = "Derleme süre sınırını aştı."
            break

        await asyncio.sleep(0.4)

    await reader

    STATE.exit_code = PROCESS.poll()
    STATE.finished_at = time.time()

    log("")

    if STATE.status == "cancelled":
        log("── İptal edildi ──")
        PROCESS = None
        return

    if STATE.exit_code == 0:
        apk = find_apk(variant)
        if apk is None:
            STATE.status = "failed"
            STATE.message = "Gradle başarılı döndü ama APK bulunamadı."
            log("HATA: " + STATE.message)
        else:
            # Copied out of the build directory so a later `clean` cannot
            # delete the artefact someone is about to download.
            target = OUTPUT_DIR / apk.name
            shutil.copy2(apk, target)

            STATE.status = "success"
            STATE.apk_path = str(target)
            STATE.apk_size = target.stat().st_size
            STATE.message = f"{apk.name} · {STATE.apk_size / (1024 * 1024):.1f} MB"

            log("── BAŞARILI ──")
            log(f"APK: {apk.name} ({STATE.apk_size / (1024 * 1024):.1f} MB)")
            log(f"Süre: {STATE.duration} sn")
    else:
        STATE.status = "failed"
        if not STATE.message:
            STATE.message = f"Gradle {STATE.exit_code} koduyla çıktı."
        log(f"── BAŞARISIZ (çıkış kodu {STATE.exit_code}) ──")

    PROCESS = None


def read_lines_sync(process, loop, mark_output) -> None:
    """
    Drain the child's output on a worker thread.

    Reading a pipe is blocking, and doing it on the event loop would freeze
    every other request — including the one streaming this log to the browser.
    """
    if process.stdout is None:
        return

    for raw in process.stdout:
        line = raw.rstrip("\n")
        loop.call_soon_threadsafe(log, line)
        loop.call_soon_threadsafe(mark_output)


def terminate_process() -> None:
    """Stop the build and everything it started."""
    global PROCESS

    if PROCESS is None or PROCESS.poll() is not None:
        return

    try:
        # The whole process group: Gradle forks compiler daemons, and killing
        # only the launcher leaves them running.
        os.killpg(os.getpgid(PROCESS.pid), signal.SIGTERM)
    except (ProcessLookupError, PermissionError, OSError):
        PROCESS.terminate()

    try:
        PROCESS.wait(timeout=15)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(os.getpgid(PROCESS.pid), signal.SIGKILL)
        except (ProcessLookupError, PermissionError, OSError):
            PROCESS.kill()


@app.get("/", response_class=HTMLResponse)
async def index() -> HTMLResponse:
    return HTMLResponse((STATIC_DIR / "index.html").read_text(encoding="utf-8"))


@app.get("/api/env")
async def api_env() -> JSONResponse:
    return JSONResponse(environment_report())


@app.get("/api/status")
async def api_status() -> JSONResponse:
    return JSONResponse(
        {
            "status": STATE.status,
            "variant": STATE.variant,
            "duration": STATE.duration,
            "exit_code": STATE.exit_code,
            "message": STATE.message,
            "apk_available": STATE.apk_path is not None and Path(STATE.apk_path).is_file(),
            "apk_size": STATE.apk_size,
            "lines": STATE.cursor,
        }
    )


@app.post("/api/build")
async def api_build(request: BuildRequest) -> JSONResponse:
    if request.variant not in ("debug", "release"):
        raise HTTPException(status_code=400, detail="Varyant debug veya release olmalı.")

    if BUILD_LOCK.locked() or STATE.status == "running":
        raise HTTPException(status_code=409, detail="Zaten bir derleme sürüyor.")

    async def guarded() -> None:
        async with BUILD_LOCK:
            try:
                await run_build(request.variant, request.api_base, request.clean)
            except Exception as error:  # noqa: BLE001
                # Any unexpected failure still has to leave the UI in a state
                # that explains itself rather than spinning forever.
                STATE.status = "failed"
                STATE.finished_at = time.time()
                STATE.message = f"Sunucu hatası: {error}"
                log("HATA: " + STATE.message)

    asyncio.create_task(guarded())

    return JSONResponse({"started": True, "variant": request.variant})


@app.post("/api/cancel")
async def api_cancel() -> JSONResponse:
    if STATE.status != "running":
        raise HTTPException(status_code=409, detail="Süren bir derleme yok.")

    STATE.status = "cancelled"
    STATE.message = "Kullanıcı iptal etti."
    terminate_process()

    return JSONResponse({"cancelled": True})


@app.get("/api/logs")
async def api_logs(after: int = 0) -> JSONResponse:
    """Log lines after a cursor, for a client that reconnects."""
    lines = list(STATE.lines)
    total = STATE.cursor

    # The deque drops the oldest lines once it is full, so the first line still
    # held corresponds to this cursor rather than to zero.
    first_held = max(0, total - len(lines))
    start = max(0, after - first_held)

    return JSONResponse({"cursor": total, "lines": lines[start:]})


@app.get("/api/logs/stream")
async def api_logs_stream(after: int = 0):
    """Server-sent events: the log as it is produced."""

    async def generator():
        cursor = after

        def unseen(from_cursor: int) -> tuple[list[str], int]:
            """Lines after `from_cursor`, and the cursor they end at."""
            lines = list(STATE.lines)
            total = STATE.cursor

            if total <= from_cursor:
                return [], from_cursor

            # The deque drops its oldest lines once full, so the first line
            # still held sits at this cursor rather than at zero.
            first_held = max(0, total - len(lines))
            start = max(0, from_cursor - first_held)

            return lines[start:], total

        def line_event(line: str) -> str:
            return f"data: {json.dumps({'line': line})}\n\n"

        def status_event(at: int) -> str:
            payload = {"status": STATE.status, "duration": STATE.duration, "cursor": at}
            return f"event: status\ndata: {json.dumps(payload)}\n\n"

        while True:
            batch, cursor = unseen(cursor)
            for line in batch:
                yield line_event(line)

            # Read once, before the final drain: the status must not be
            # re-read after sleeping, or a build that starts again in the
            # meantime would keep this stream open against the wrong build.
            finished = STATE.status != "running"

            yield status_event(cursor)

            if finished:
                # One last drain before closing. The build's final lines — the
                # result and the APK name — are appended around the moment the
                # status flips, so returning here would lose exactly the part
                # worth reading.
                await asyncio.sleep(0.4)

                tail, cursor = unseen(cursor)
                for line in tail:
                    yield line_event(line)

                yield status_event(cursor)
                yield "event: done\ndata: {}\n\n"
                return

            await asyncio.sleep(0.5)

    return StreamingResponse(
        generator(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            # Spaces sit behind a proxy that would otherwise buffer the stream
            # and deliver it all at the end, defeating the point.
            "X-Accel-Buffering": "no",
        },
    )


@app.get("/api/download")
async def api_download() -> FileResponse:
    if not STATE.apk_path or not Path(STATE.apk_path).is_file():
        raise HTTPException(status_code=404, detail="İndirilebilir APK yok.")

    path = Path(STATE.apk_path)

    return FileResponse(
        path,
        media_type="application/vnd.android.package-archive",
        filename=path.name,
    )


@app.get("/api/log-file")
async def api_log_file() -> FileResponse:
    if not LOG_FILE.is_file():
        raise HTTPException(status_code=404, detail="Log dosyası yok.")

    return FileResponse(LOG_FILE, media_type="text/plain", filename="build.log")


app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(
        app,
        host="0.0.0.0",
        port=int(os.environ.get("PORT", "7860")),
        log_level="info",
    )
