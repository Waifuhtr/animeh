#!/usr/bin/env python3
"""Symbols used from a sub-package the file only wildcard-imports the parent of.

`import androidx.compose.runtime.*` does not reach
`androidx.compose.runtime.saveable.rememberSaveable`; a sub-package is a
separate package. kotlinc run without the Android classpath cannot see this
(every unresolved symbol looks the same to it), so this checks it directly.

Each symbol is listed with the package it must come from and how it is written
at the use site:

  call   `symbol(`        — a function, including Modifier extensions
  value  `symbol` bare    — an object, a composition local, a class
  unit   `123.symbol`     — the dp/sp extension properties on a number
"""
import os, re, sys

CALL, VALUE, UNIT = "call", "value", "unit"

SYMBOLS = {
    # androidx.compose.runtime.saveable — the one that got through
    "rememberSaveable": ("androidx.compose.runtime.saveable", CALL),
    "listSaver": ("androidx.compose.runtime.saveable", CALL),
    "mapSaver": ("androidx.compose.runtime.saveable", CALL),
    "rememberSaveableStateHolder": ("androidx.compose.runtime.saveable", CALL),

    "collectAsStateWithLifecycle": ("androidx.lifecycle.compose", CALL),
    "LifecycleStartEffect": ("androidx.lifecycle.compose", CALL),
    "LifecycleResumeEffect": ("androidx.lifecycle.compose", CALL),
    "LifecycleEventEffect": ("androidx.lifecycle.compose", CALL),
    "hiltViewModel": ("androidx.hilt.navigation.compose", CALL),
    "rememberNavController": ("androidx.navigation.compose", CALL),

    "rememberScrollState": ("androidx.compose.foundation", CALL),
    "verticalScroll": ("androidx.compose.foundation", CALL),
    "horizontalScroll": ("androidx.compose.foundation", CALL),
    "clickable": ("androidx.compose.foundation", CALL),
    "combinedClickable": ("androidx.compose.foundation", CALL),
    "background": ("androidx.compose.foundation", CALL),
    "border": ("androidx.compose.foundation", CALL),
    "selectable": ("androidx.compose.foundation.selection", CALL),
    "toggleable": ("androidx.compose.foundation.selection", CALL),

    "clip": ("androidx.compose.ui.draw", CALL),
    "alpha": ("androidx.compose.ui.draw", CALL),
    "shadow": ("androidx.compose.ui.draw", CALL),
    "blur": ("androidx.compose.ui.draw", CALL),
    "drawBehind": ("androidx.compose.ui.draw", CALL),
    "drawWithContent": ("androidx.compose.ui.draw", CALL),
    "graphicsLayer": ("androidx.compose.ui.graphics", CALL),
    "zIndex": ("androidx.compose.ui", CALL),
    "pointerInput": ("androidx.compose.ui.input.pointer", CALL),
    "nestedScroll": ("androidx.compose.ui.input.nestedscroll", CALL),
    "semantics": ("androidx.compose.ui.semantics", CALL),
    "testTag": ("androidx.compose.ui.platform", CALL),
    "onGloballyPositioned": ("androidx.compose.ui.layout", CALL),

    "detectTapGestures": ("androidx.compose.foundation.gestures", CALL),
    "detectDragGestures": ("androidx.compose.foundation.gestures", CALL),
    "detectHorizontalDragGestures": ("androidx.compose.foundation.gestures", CALL),

    "stringResource": ("androidx.compose.ui.res", CALL),
    "painterResource": ("androidx.compose.ui.res", CALL),
    "colorResource": ("androidx.compose.ui.res", CALL),
    "pluralStringResource": ("androidx.compose.ui.res", CALL),

    "AndroidView": ("androidx.compose.ui.viewinterop", CALL),
    "AsyncImage": ("coil.compose", CALL),
    "SubcomposeAsyncImage": ("coil.compose", CALL),
    "rememberAsyncImagePainter": ("coil.compose", CALL),
    "BackHandler": ("androidx.activity.compose", CALL),
    "setContent": ("androidx.activity.compose", CALL),
    "rememberLauncherForActivityResult": ("androidx.activity.compose", CALL),
    "animateFloatAsState": ("androidx.compose.animation.core", CALL),
    "rememberInfiniteTransition": ("androidx.compose.animation.core", CALL),
    "AnimatedVisibility": ("androidx.compose.animation", CALL),
    "rememberPagerState": ("androidx.compose.foundation.pager", CALL),
    "HorizontalPager": ("androidx.compose.foundation.pager", CALL),
    "rememberLazyListState": ("androidx.compose.foundation.lazy", CALL),
    "LazyColumn": ("androidx.compose.foundation.lazy", CALL),
    "LazyRow": ("androidx.compose.foundation.lazy", CALL),
    "LazyVerticalGrid": ("androidx.compose.foundation.lazy.grid", CALL),
    "LazyHorizontalGrid": ("androidx.compose.foundation.lazy.grid", CALL),
    "GridItemSpan": ("androidx.compose.foundation.lazy.grid", CALL),
    "rememberLazyGridState": ("androidx.compose.foundation.lazy.grid", CALL),
    "GridCells": ("androidx.compose.foundation.lazy.grid", VALUE),
    "LifecycleResumeEffect": ("androidx.lifecycle.compose", CALL),
    "ImageDecoderDecoder": ("coil.decode", VALUE),
    "GifDecoder": ("coil.decode", VALUE),
    "imageLoader": ("coil", VALUE),
    "systemBarsPadding": ("androidx.compose.foundation.layout", CALL),
    "statusBarsPadding": ("androidx.compose.foundation.layout", CALL),
    "navigationBarsPadding": ("androidx.compose.foundation.layout", CALL),
    "imePadding": ("androidx.compose.foundation.layout", CALL),

    "TextOverflow": ("androidx.compose.ui.text.style", VALUE),
    "TextAlign": ("androidx.compose.ui.text.style", VALUE),
    "TextDecoration": ("androidx.compose.ui.text.style", VALUE),
    "FontWeight": ("androidx.compose.ui.text.font", VALUE),
    "FontFamily": ("androidx.compose.ui.text.font", VALUE),
    "ContentScale": ("androidx.compose.ui.layout", VALUE),
    "LocalContext": ("androidx.compose.ui.platform", VALUE),
    "LocalConfiguration": ("androidx.compose.ui.platform", VALUE),
    "LocalDensity": ("androidx.compose.ui.platform", VALUE),
    "LocalView": ("androidx.compose.ui.platform", VALUE),
    "LocalSoftwareKeyboardController": ("androidx.compose.ui.platform", VALUE),
    "LocalFocusManager": ("androidx.compose.ui.platform", VALUE),
    "LocalUriHandler": ("androidx.compose.ui.platform", VALUE),
    "LocalContentColor": ("androidx.compose.material3", VALUE),

    "dp": ("androidx.compose.ui.unit", UNIT),
    "sp": ("androidx.compose.ui.unit", UNIT),
}

def uses(body, symbol, kind):
    if kind == CALL:
        # `(` or `{`: a trailing lambda is a call with no parentheses at all,
        # which is exactly how `rememberSaveable { ... }` is written — and how
        # the first version of this check missed the bug it was written for.
        return re.search(rf"(?<![\w.]){symbol}\s*[({{]", body) or re.search(rf"\.{symbol}\s*[({{]", body)
    if kind == UNIT:
        return re.search(rf"\d\s*\.\s*{symbol}\b", body)
    return re.search(rf"(?<![\w.]){symbol}\b\s*(?![:=][^=])", body)

def declared_here(body, symbol):
    # `val Float.dp`, `fun Modifier.shimmer`, `private val dp`, `class Foo`
    return re.search(rf"\b(fun|val|var|class|object|interface)\s+(<[^>]*>\s*)?([\w.<>?]+\.)?{symbol}\b", body)

def check(root):
    problems, checked = [], 0
    for dirpath, _dirs, files in os.walk(root):
        for name in sorted(files):
            if not name.endswith(".kt"):
                continue
            path = os.path.join(dirpath, name)
            src = open(path, encoding="utf-8").read()

            imports, wildcards = set(), set()
            for line in src.splitlines():
                line = line.strip()
                if line.startswith("import "):
                    spec = line[len("import "):].split(" as ")[0].strip()
                    (wildcards.add(spec[:-2]) if spec.endswith(".*") else imports.add(spec))

            body = "\n".join(l for l in src.splitlines() if not l.strip().startswith("import "))
            body = re.sub(r"/\*.*?\*/", " ", body, flags=re.S)
            body = re.sub(r"//[^\n]*", " ", body)

            for symbol, (pkg, kind) in SYMBOLS.items():
                if not uses(body, symbol, kind):
                    continue
                checked += 1
                if f"{pkg}.{symbol}" in imports or pkg in wildcards:
                    continue
                if f"{pkg}.{symbol}" in src or declared_here(body, symbol):
                    continue
                problems.append(f"{path}: '{symbol}' used but not imported from {pkg}")
    return checked, problems

if __name__ == "__main__":
    checked, problems = check(sys.argv[1] if len(sys.argv) > 1 else ".")
    for p in problems:
        print(p)
    print(f"\n{checked} symbol uses checked, {len(problems)} problem(s).")
    sys.exit(1 if problems else 0)
