# Animeh APK builder — a Hugging Face Docker Space.
#
# Everything the Android build needs, baked into the image: JDK 17, the Android
# SDK, and the Gradle distribution. Baking rather than fetching at runtime is
# the whole point — a Space container is ephemeral, so anything downloaded on
# first run is downloaded again on every restart.

FROM eclipse-temurin:17-jdk-jammy

# ── System packages ──────────────────────────────────────────────────────
# `unzip` for the SDK archives, `git` because Gradle plugins occasionally
# shell out to it, `python3` for the build server.
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        unzip \
        zip \
        git \
        ca-certificates \
        python3 \
        python3-pip \
        python3-venv \
    && rm -rf /var/lib/apt/lists/*

# ── Android SDK ──────────────────────────────────────────────────────────
# Pinned versions: an APK that builds today should build the same way in six
# months, and "latest" is how a build starts failing without anything changing.
ENV ANDROID_HOME=/opt/android-sdk \
    ANDROID_SDK_ROOT=/opt/android-sdk \
    CMDLINE_TOOLS_VERSION=11076708 \
    ANDROID_PLATFORM=android-35 \
    ANDROID_BUILD_TOOLS=35.0.0

RUN mkdir -p "${ANDROID_HOME}/cmdline-tools" \
    && curl -fsSL -o /tmp/cmdline-tools.zip \
        "https://dl.google.com/android/repository/commandlinetools-linux-${CMDLINE_TOOLS_VERSION}_latest.zip" \
    && unzip -q /tmp/cmdline-tools.zip -d "${ANDROID_HOME}/cmdline-tools" \
    # sdkmanager expects to live in cmdline-tools/<channel>/bin, and the archive
    # unpacks to cmdline-tools/ — without this move every sdkmanager call fails
    # with "Could not determine SDK root".
    && mv "${ANDROID_HOME}/cmdline-tools/cmdline-tools" "${ANDROID_HOME}/cmdline-tools/latest" \
    && rm /tmp/cmdline-tools.zip

ENV PATH="${PATH}:${ANDROID_HOME}/cmdline-tools/latest/bin:${ANDROID_HOME}/platform-tools"

# `yes |` accepts the SDK licences non-interactively. The `|| true` guards the
# SIGPIPE that `yes` takes when sdkmanager stops reading, which would otherwise
# fail the layer despite the licences having been accepted.
RUN yes | sdkmanager --licenses > /dev/null 2>&1 || true \
    && sdkmanager --install \
        "platform-tools" \
        "platforms;${ANDROID_PLATFORM}" \
        "build-tools;${ANDROID_BUILD_TOOLS}" \
        > /dev/null

# ── Gradle ───────────────────────────────────────────────────────────────
# Installed alongside the wrapper so a build never waits on the wrapper's own
# distribution download. The wrapper still works; this just pre-populates it.
ENV GRADLE_VERSION=8.11.1 \
    GRADLE_USER_HOME=/opt/gradle-home

RUN curl -fsSL -o /tmp/gradle.zip \
        "https://services.gradle.org/distributions/gradle-${GRADLE_VERSION}-bin.zip" \
    && unzip -q /tmp/gradle.zip -d /opt \
    && rm /tmp/gradle.zip \
    && ln -s "/opt/gradle-${GRADLE_VERSION}/bin/gradle" /usr/local/bin/gradle

# ── The build server ─────────────────────────────────────────────────────
WORKDIR /app

COPY requirements.txt /app/requirements.txt
RUN pip3 install --no-cache-dir -r /app/requirements.txt

COPY app.py /app/app.py
COPY static /app/static
COPY android /app/android

# ── User ─────────────────────────────────────────────────────────────────
# Hugging Face runs Spaces as uid 1000. Everything the build writes to —
# the project directory, Gradle's home, the SDK — has to be owned by it, or
# the first build fails on a permission error rather than on anything real.
RUN useradd -m -u 1000 builder \
    && mkdir -p "${GRADLE_USER_HOME}" /app/output \
    && chmod +x /app/android/gradlew \
    && chown -R builder:builder /app "${GRADLE_USER_HOME}" "${ANDROID_HOME}"

USER builder

# `local.properties` points Gradle at the SDK. Written here rather than
# committed, because the path is a property of this image.
RUN printf 'sdk.dir=%s\n' "${ANDROID_HOME}" > /app/android/local.properties

# Warm the dependency cache so the first build in a fresh container is not
# also the first download of AndroidX and Compose. Best-effort: a failure here
# must not fail the image, since the build server can still fetch them at run
# time — it is only slower.
RUN cd /app/android \
    && (./gradlew --no-daemon --quiet dependencies > /dev/null 2>&1 \
        || ./gradlew --no-daemon --quiet help > /dev/null 2>&1 \
        || true)

ENV PYTHONUNBUFFERED=1 \
    PORT=7860

EXPOSE 7860

CMD ["python3", "-u", "/app/app.py"]
