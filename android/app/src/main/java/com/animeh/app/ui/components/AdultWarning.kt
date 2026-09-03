package com.animeh.app.ui.components

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource
import com.animeh.app.R
import com.animeh.app.ui.theme.StatusWarning

/**
 * The question asked before an episode of a flagged series plays.
 *
 * Shared between the anime page and the player rather than living on one of
 * them. The anime page is where it is meant to appear and where it reads as
 * part of the page; the player is the backstop, because playback can also be
 * started from a home rail, the library, a resumed episode or a deep link,
 * and a gate that only guards one screen guards nothing.
 *
 * Not dismissible by tapping outside, and "Vazgeç" is the plain-looking
 * action: an accidental tap on a poster should not start playing something
 * somebody deliberately marked, and the pause is the whole point.
 */
@Composable
fun AdultWarningDialog(
    onDismiss: () -> Unit,
    onContinue: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        icon = { Icon(Icons.Filled.Warning, contentDescription = null, tint = StatusWarning) },
        title = { Text(stringResource(R.string.adult_warning_title)) },
        text = { Text(stringResource(R.string.adult_warning_body)) },
        confirmButton = {
            TextButton(onClick = onContinue) {
                Text(stringResource(R.string.adult_warning_continue))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
        },
    )
}
