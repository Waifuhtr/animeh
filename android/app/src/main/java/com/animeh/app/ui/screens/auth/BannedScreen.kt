package com.animeh.app.ui.screens.auth

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.animeh.app.R
import com.animeh.app.core.AppError
import com.animeh.app.ui.theme.StatusError
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/**
 * What a suspended account sees instead of the app.
 *
 * Full screen and above everything, because the alternative is a catalog that
 * loads and a library that does not, with a different error under each — which
 * reads as the app being broken rather than as a decision somebody made.
 *
 * It says which it is and, for a suspension, when it ends. "Come back later"
 * without a date is the version of this screen people write to support about.
 */
@Composable
fun BannedScreen(
    ban: AppError.Banned,
    onSignOut: () -> Unit,
) {
    Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
        Column(
            Modifier
                .fillMaxSize()
                .padding(32.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Icon(
                Icons.Filled.Lock,
                contentDescription = null,
                modifier = Modifier.size(56.dp),
                tint = StatusError,
            )

            Spacer(Modifier.height(20.dp))

            Text(
                stringResource(R.string.banned_title),
                style = MaterialTheme.typography.headlineSmall,
                textAlign = TextAlign.Center,
            )

            Spacer(Modifier.height(10.dp))

            Text(
                text = if (ban.permanent || ban.expiresAt.isBlank()) {
                    stringResource(R.string.banned_permanent)
                } else {
                    stringResource(R.string.banned_until, formatUntil(ban.expiresAt))
                },
                style = MaterialTheme.typography.bodyMedium,
                color = TextSecondary,
                textAlign = TextAlign.Center,
            )

            if (ban.reason.isNotBlank()) {
                Spacer(Modifier.height(14.dp))
                Text(
                    stringResource(R.string.banned_reason, ban.reason),
                    style = MaterialTheme.typography.bodyMedium,
                    textAlign = TextAlign.Center,
                )
            }

            Spacer(Modifier.height(14.dp))

            Text(
                stringResource(R.string.banned_contact),
                style = MaterialTheme.typography.bodySmall,
                color = TextMuted,
                textAlign = TextAlign.Center,
            )

            Spacer(Modifier.height(28.dp))

            OutlinedButton(onClick = onSignOut, modifier = Modifier.fillMaxWidth()) {
                Text(stringResource(R.string.banned_sign_out))
            }
        }
    }
}

/**
 * The server's UTC timestamp, in the reader's own time.
 *
 * Shown to the minute rather than the second: "13 Mart 2026 14:30" is a date
 * someone can plan around, and the seconds are noise on a sanction measured
 * in days.
 */
private fun formatUntil(value: String): String {
    val parser = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }

    val parsed = try {
        parser.parse(value)
    } catch (error: java.text.ParseException) {
        null
    }

    // Falling back to the raw value rather than to nothing: a timestamp in a
    // format this did not expect is still more use than an empty sentence.
    return parsed?.let {
        SimpleDateFormat("d MMMM yyyy HH:mm", Locale.getDefault()).format(Date(it.time))
    } ?: value
}
