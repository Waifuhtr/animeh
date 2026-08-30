package com.animeh.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.animeh.app.data.prefs.SessionStore
import com.animeh.app.ui.navigation.AnimehApp
import com.animeh.app.ui.theme.AnimehTheme
import dagger.hilt.android.AndroidEntryPoint
import javax.inject.Inject

@AndroidEntryPoint
class MainActivity : ComponentActivity() {

    @Inject lateinit var sessionStore: SessionStore

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        setContent {
            AnimehTheme {
                // Read from the store rather than a ViewModel so that a token
                // refresh or a sign-out anywhere in the app immediately changes
                // which tabs are drawn.
                val authState by sessionStore.state.collectAsStateWithLifecycle()

                AnimehApp(authState = authState)
            }
        }
    }
}
