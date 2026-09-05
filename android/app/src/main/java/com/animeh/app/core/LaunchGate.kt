package com.animeh.app.core

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Whether the app has anything to show yet.
 *
 * The home screen builds itself out of skeleton blocks while its feed loads,
 * which is the right thing on a pull-to-refresh and the wrong thing as a first
 * impression: opening the app landed on a page of empty grey rectangles, and
 * that is what people remember an app as being like.
 *
 * So the launch screen stays up until this says otherwise. It is a flag rather
 * than a callback because the two ends are far apart — the home screen's view
 * model knows when the feed has settled, and the activity is what draws over
 * it — and neither should have to hold a reference to the other.
 *
 * Set once per process. A later refresh is a refresh, not a launch.
 */
@Singleton
class LaunchGate @Inject constructor() {

    private val _ready = MutableStateFlow(false)

    /** True once the first home feed has settled, one way or the other. */
    val ready: StateFlow<Boolean> = _ready.asStateFlow()

    /**
     * The first feed has arrived — or failed, which is equally a reason to
     * stop covering the screen: an error the viewer can read and retry beats
     * a launch animation that never ends.
     */
    fun markReady() {
        _ready.value = true
    }
}
