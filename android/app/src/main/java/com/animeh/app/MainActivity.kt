package com.animeh.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.core.AccountGate
import com.animeh.app.data.prefs.SessionStore
import com.animeh.app.data.repository.AuthRepository
import com.animeh.app.ui.navigation.AnimehApp
import com.animeh.app.ui.screens.auth.BannedScreen
import com.animeh.app.ui.theme.AnimehTheme
import dagger.hilt.android.AndroidEntryPoint
import kotlinx.coroutines.launch
import javax.inject.Inject

@AndroidEntryPoint
class MainActivity : ComponentActivity() {

    @Inject lateinit var sessionStore: SessionStore

    @Inject lateinit var authRepository: AuthRepository

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        setContent {
            AnimehTheme {
                // Read from the store rather than a ViewModel so that a token
                // refresh or a sign-out anywhere in the app immediately changes
                // which tabs are drawn.
                val authState by sessionStore.state.collectAsStateWithLifecycle()

                // A suspension is checked here rather than inside the graph:
                // it applies to every screen at once, and putting it above the
                // NavHost means there is no route that can be reached around
                // it — including one restored from the back stack.
                val ban by AccountGate.ban.collectAsStateWithLifecycle()
                val scope = rememberCoroutineScope()

                val current = ban
                if (current != null) {
                    BannedScreen(
                        ban = current,
                        onSignOut = { scope.launch { authRepository.logout() } },
                    )
                } else {
                    AnimehApp(authState = authState)
                }
            }
        }
    }
}
