#!/usr/bin/env python3
"""Methods on an Activity subclass whose name is already taken by the platform.

`Activity.setImmersive(boolean)` exists and means something else entirely, so
declaring one without `override` is an error the compiler reports as "hides
member of supertype". kotlinc run without android.jar cannot see the supertype
at all, so it says nothing. This lists the names to stay off.
"""
import os, re, sys

ACTIVITY_BASES = (
    "ComponentActivity", "AppCompatActivity", "Activity", "FragmentActivity",
    "MediaSessionActivity", "PlayerActivity",
)

# Public/protected methods on Activity, ComponentActivity and Context that a
# subclass method could shadow. Property names count too: Kotlin's `val foo`
# generates `getFoo()`, `var foo` also `setFoo()`.
PLATFORM = {
    "setImmersive", "setIntent", "setTitle", "setTitleColor", "setContentView",
    "setResult", "setRequestedOrientation", "setVisible", "setTheme",
    "setFinishOnTouchOutside", "setTaskDescription", "setShowWhenLocked",
    "setTurnScreenOn", "setInheritShowWhenLocked", "setTranslucent",
    "setVrModeEnabled", "setActionBar", "setDefaultKeyMode", "setProgress",
    "setProgressBarVisibility", "setPictureInPictureParams", "setLocusContext",
    "setRecentsScreenshotEnabled", "setEnterSharedElementCallback",
    "setExitSharedElementCallback", "setMediaController", "setVolumeControlStream",
    "setSecondaryProgress", "setFeatureDrawable", "setSupportActionBar",
    "getIntent", "getWindow", "getResources", "getApplicationContext",
    "getTitle", "getSystemService", "getWindowManager", "getMenuInflater",
    "getLayoutInflater", "getCallingActivity", "getCallingPackage",
    "getComponentName", "getContentScene", "getCurrentFocus", "getActionBar",
    "getTaskId", "getReferrer", "getParent", "getFragmentManager",
    "getLoaderManager", "getVoiceInteractor", "getLifecycle", "getViewModelStore",
    "getSavedStateRegistry", "getOnBackPressedDispatcher", "getActivityResultRegistry",
    "getDefaultViewModelProviderFactory", "getApplication", "getBaseContext",
    "getCacheDir", "getFilesDir", "getPackageName", "getPackageManager",
    "getContentResolver", "getMainLooper", "getTheme", "getAssets",
    "getChangingConfigurations", "getMaxNumPictureInPictureActions",
    "isFinishing", "isChangingConfigurations", "isTaskRoot", "isDestroyed",
    "isImmersive", "isChild", "isVoiceInteraction", "isInMultiWindowMode",
    "isInPictureInPictureMode", "isLocalVoiceInteractionSupported",
    "isActivityTransitionRunning", "isLaunchedFromBubble",
    "finish", "finishAffinity", "finishActivity", "finishAndRemoveTask",
    "finishAfterTransition", "finishFromChild", "recreate", "reportFullyDrawn",
    "invalidateOptionsMenu", "openOptionsMenu", "closeOptionsMenu",
    "openContextMenu", "closeContextMenu", "registerForContextMenu",
    "unregisterForContextMenu", "startActivity", "startActivities",
    "startActivityForResult", "startActivityIfNeeded", "startIntentSender",
    "startSearch", "startLockTask", "stopLockTask", "startPostponedEnterTransition",
    "postponeEnterTransition", "registerForActivityResult", "requestPermissions",
    "shouldShowRequestPermissionRationale", "requestWindowFeature",
    "requireViewById", "findViewById", "addContentView", "moveTaskToBack",
    "navigateUpTo", "navigateUpToFromChild", "releaseInstance", "takeKeyEvents",
    "enterPictureInPictureMode", "showLockTaskEscapeMessage", "triggerSearch",
    "unregisterReceiver", "registerReceiver", "sendBroadcast", "bindService",
    "unbindService", "startService", "stopService", "startForegroundService",
    "checkSelfPermission", "dump", "runOnUiThread", "overridePendingTransition",
    "onBackPressed", "onUserLeaveHint", "onWindowFocusChanged", "onNewIntent",
    "onConfigurationChanged", "onSaveInstanceState", "onRestoreInstanceState",
    "onCreate", "onStart", "onResume", "onPause", "onStop", "onDestroy",
    "onRestart", "onLowMemory", "onTrimMemory", "onAttachedToWindow",
    "onDetachedFromWindow", "onActivityResult", "onRequestPermissionsResult",
    "onKeyDown", "onKeyUp", "onTouchEvent", "onCreateOptionsMenu",
    "onOptionsItemSelected", "onPrepareOptionsMenu", "onPictureInPictureModeChanged",
    "onMultiWindowModeChanged", "onUserInteraction", "onPostCreate", "onPostResume",
}

CLASS_RE = re.compile(r"^\s*(?:@\w+(?:\([^)]*\))?\s*)*(?:\w+\s+)*class\s+(\w+)[^:{]*:\s*([^{]+)\{", re.M)
MEMBER_RE = re.compile(r"^\s*((?:\w+\s+)*)(fun|val|var)\s+(\w+)", re.M)

def jvm_names(kind, name):
    if kind == "fun":
        return {name}
    cap = name[0].upper() + name[1:]
    return {f"get{cap}"} | ({f"set{cap}"} if kind == "var" else set())

def class_body(src, brace_index):
    """The text between a class's own braces, and nothing after them.

    Without this the scan ran off the end of the class and read every
    top-level function in the file as a member of it.
    """
    depth, i = 0, brace_index
    while i < len(src):
        if src[i] == "{":
            depth += 1
        elif src[i] == "}":
            depth -= 1
            if depth == 0:
                return src[brace_index + 1:i]
        i += 1
    return src[brace_index + 1:]


def check(root):
    problems, scanned = [], 0
    for dirpath, _dirs, files in os.walk(root):
        for fname in sorted(files):
            if not fname.endswith(".kt"):
                continue
            path = os.path.join(dirpath, fname)
            src = open(path, encoding="utf-8").read()

            for m in CLASS_RE.finditer(src):
                cls, supers = m.group(1), m.group(2)
                if not any(b in supers for b in ACTIVITY_BASES):
                    continue
                scanned += 1
                body = class_body(src, m.end() - 1)
                for mem in MEMBER_RE.finditer(body):
                    mods, kind, name = mem.group(1), mem.group(2), mem.group(3)
                    if "override" in mods:
                        continue
                    for jvm in jvm_names(kind, name):
                        if jvm in PLATFORM:
                            line = src[:m.end() + mem.start()].count("\n") + 1  # approximate
                            problems.append(
                                f"{path}:{line}: {cls}.{name} generates '{jvm}', "
                                f"which the platform already declares"
                            )
    return scanned, problems

if __name__ == "__main__":
    scanned, problems = check(sys.argv[1] if len(sys.argv) > 1 else ".")
    for p in problems:
        print(p)
    print(f"\n{scanned} activity class(es) scanned, {len(problems)} problem(s).")
    sys.exit(1 if problems else 0)
