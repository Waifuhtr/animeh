package com.animeh.app.ui.screens.auth

import android.content.Context
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.ArrowForward
import androidx.compose.material.icons.filled.AutoAwesome
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material.icons.outlined.Shield
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.ui.theme.AccentBright
import com.animeh.app.ui.theme.AccentDeep
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.StatusError
import com.animeh.app.ui.theme.StatusSuccess
import com.animeh.app.ui.theme.StatusWarning
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.SurfaceOverlay
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextPrimary
import com.animeh.app.ui.theme.TextSecondary

/**
 * The pieces the sign-in and sign-up screens are drawn from.
 *
 * These two screens are the only ones in the app with artwork behind the
 * content and a card floating over it, so their parts live here rather than in
 * `ui/components`: nothing else should start looking like a login page by
 * accident.
 *
 * The artwork itself is not in the repository. It is looked up by name in
 * `assets/auth/`, so it can be replaced without touching any code — see
 * `assets/auth/OKU.md`. A missing file is not an error; the cover falls back
 * to a gradient and the screen works exactly as well.
 */

/** How tall the artwork is, as a share of the screen. */
const val LOGIN_COVER_FRACTION = 0.46f
const val REGISTER_COVER_FRACTION = 0.30f

/**
 * Find the cover file whatever its extension.
 *
 * Matching on the base name rather than a fixed path is what makes "drop your
 * image in and rebuild" true: `login.jpg`, `login.png` and `login.webp` all
 * answer, and nobody has to know which one the code was written against.
 */
private fun coverAsset(context: Context, base: String): String? {
    val names = runCatching { context.assets.list("auth") }.getOrNull().orEmpty()
    val match = names.firstOrNull { it.substringBeforeLast('.') == base } ?: return null

    return "file:///android_asset/auth/$match"
}

/**
 * The artwork at the top of an auth screen, with the headline over it.
 *
 * The headline sits on the image rather than under it, so the two read as one
 * thing; the scrim underneath it is what keeps white text legible over
 * whatever picture ends up here.
 */
@Composable
fun AuthCover(
    asset: String,
    lead: String,
    accent: String,
    subtitleLead: String,
    subtitleAccent: String = "",
    heightFraction: Float,
    onBack: (() -> Unit)? = null,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val model = remember(asset) { coverAsset(context, asset) }

    // Measured against the screen rather than against the incoming
    // constraint: this sits inside a scrolling column, where the available
    // height is unbounded and a fraction of it would be meaningless.
    val height = LocalConfiguration.current.screenHeightDp.dp * heightFraction

    Box(modifier.fillMaxWidth()) {
        Box(Modifier.fillMaxWidth().height(height)) {
            if (model != null) {
                AsyncImage(
                    model = model,
                    contentDescription = null,
                    contentScale = ContentScale.Crop,
                    modifier = Modifier.fillMaxSize(),
                )
            } else {
                // No artwork yet. A flat black rectangle would look like a
                // failed load; this looks deliberate until the file arrives.
                Box(
                    Modifier
                        .fillMaxSize()
                        .background(
                            Brush.linearGradient(
                                listOf(AccentDeep.copy(alpha = 0.55f), SurfaceCard, MaterialTheme.colorScheme.background)
                            )
                        )
                )
            }

            // Two scrims: a light one at the top so the back button and the
            // status bar stay visible over a bright sky, and a deep one at the
            // bottom that the headline sits in and that hands over to the page.
            Box(
                Modifier
                    .fillMaxWidth()
                    .height(120.dp)
                    .align(Alignment.TopCenter)
                    .background(Brush.verticalGradient(listOf(Color.Black.copy(alpha = 0.45f), Color.Transparent)))
            )
            Box(
                Modifier
                    .fillMaxWidth()
                    .height(height / 2)
                    .align(Alignment.BottomCenter)
                    .background(
                        Brush.verticalGradient(
                            listOf(Color.Transparent, MaterialTheme.colorScheme.background)
                        )
                    )
            )

            if (onBack != null) {
                IconButton(
                    onClick = onBack,
                    modifier = Modifier
                        .align(Alignment.TopStart)
                        .statusBarsPadding()
                        .padding(8.dp)
                        .size(40.dp)
                        .clip(CircleShape)
                        .background(Color.Black.copy(alpha = 0.45f)),
                ) {
                    Icon(
                        Icons.AutoMirrored.Filled.ArrowBack,
                        stringResource(R.string.back),
                        tint = Color.White,
                    )
                }
            }

            Column(
                Modifier
                    .align(Alignment.BottomStart)
                    .fillMaxWidth()
                    .padding(start = 24.dp, end = 24.dp, bottom = 4.dp),
            ) {
                Text(
                    buildAnnotatedString {
                        append(lead)
                        withStyle(SpanStyle(color = AccentBright)) { append(accent) }
                    },
                    style = MaterialTheme.typography.displaySmall,
                    fontWeight = FontWeight.Bold,
                    color = TextPrimary,
                )

                Spacer(Modifier.height(6.dp))

                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(
                        Icons.Filled.AutoAwesome,
                        null,
                        tint = AccentBright,
                        modifier = Modifier.size(16.dp),
                    )
                    Spacer(Modifier.width(8.dp))
                    Text(
                        buildAnnotatedString {
                            append(subtitleLead)
                            withStyle(SpanStyle(color = AccentBright)) { append(subtitleAccent) }
                        },
                        style = MaterialTheme.typography.bodyMedium,
                        color = TextSecondary,
                    )
                }
            }
        }
    }
}

/** The panel the fields sit in, lifted off the page. */
@Composable
fun AuthCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(22.dp),
        color = SurfaceCard.copy(alpha = 0.72f),
        border = androidx.compose.foundation.BorderStroke(1.dp, Color.White.copy(alpha = 0.06f)),
    ) {
        Column(Modifier.padding(18.dp), content = content)
    }
}

/**
 * One field of the form.
 *
 * Placeholder rather than a floating label, and an icon rather than a caption:
 * these forms have four fields at most and every one of them is obvious from
 * its icon, so the labels were costing a line each for nothing.
 */
@Composable
fun AuthField(
    value: String,
    onValueChange: (String) -> Unit,
    placeholder: String,
    icon: ImageVector,
    keyboardType: KeyboardType = KeyboardType.Text,
    imeAction: ImeAction = ImeAction.Next,
    onDone: (() -> Unit)? = null,
    modifier: Modifier = Modifier,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        placeholder = { Text(placeholder, color = TextMuted) },
        leadingIcon = { Icon(icon, null, tint = TextMuted) },
        singleLine = true,
        shape = RoundedCornerShape(14.dp),
        colors = authFieldColors(),
        keyboardOptions = KeyboardOptions(keyboardType = keyboardType, imeAction = imeAction),
        keyboardActions = KeyboardActions(onDone = { onDone?.invoke() }),
        modifier = modifier.fillMaxWidth(),
    )
}

/** [AuthField] with the eye that reveals what was typed. */
@Composable
fun AuthPasswordField(
    value: String,
    onValueChange: (String) -> Unit,
    placeholder: String,
    icon: ImageVector,
    imeAction: ImeAction = ImeAction.Next,
    onDone: (() -> Unit)? = null,
    modifier: Modifier = Modifier,
) {
    var visible by remember { mutableStateOf(false) }

    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        placeholder = { Text(placeholder, color = TextMuted) },
        leadingIcon = { Icon(icon, null, tint = TextMuted) },
        trailingIcon = {
            IconButton(onClick = { visible = !visible }) {
                Icon(
                    if (visible) Icons.Filled.VisibilityOff else Icons.Filled.Visibility,
                    stringResource(if (visible) R.string.auth_password_hide else R.string.auth_password_show),
                    tint = TextMuted,
                )
            }
        },
        singleLine = true,
        shape = RoundedCornerShape(14.dp),
        colors = authFieldColors(),
        visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = imeAction),
        keyboardActions = KeyboardActions(onDone = { onDone?.invoke() }),
        modifier = modifier.fillMaxWidth(),
    )
}

@Composable
private fun authFieldColors() = OutlinedTextFieldDefaults.colors(
    focusedContainerColor = SurfaceOverlay.copy(alpha = 0.55f),
    unfocusedContainerColor = SurfaceOverlay.copy(alpha = 0.35f),
    focusedBorderColor = AccentPrimary.copy(alpha = 0.7f),
    unfocusedBorderColor = Color.White.copy(alpha = 0.07f),
    cursorColor = AccentBright,
    focusedTextColor = TextPrimary,
    unfocusedTextColor = TextPrimary,
)

/**
 * The one button on the screen, and it looks like it.
 *
 * The gradient is the app's accent read left to right, so the primary action
 * is the brightest thing on a page that is otherwise dark blocks and grey text.
 */
@Composable
fun AuthSubmitButton(
    text: String,
    onClick: () -> Unit,
    enabled: Boolean,
    loading: Boolean,
    modifier: Modifier = Modifier,
) {
    Button(
        onClick = onClick,
        enabled = enabled && !loading,
        shape = RoundedCornerShape(16.dp),
        contentPadding = PaddingValues(0.dp),
        elevation = null,
        colors = ButtonDefaults.buttonColors(
            containerColor = Color.Transparent,
            disabledContainerColor = Color.Transparent,
        ),
        modifier = modifier.fillMaxWidth().height(54.dp),
    ) {
        Box(
            Modifier
                .fillMaxSize()
                .background(
                    if (enabled && !loading) {
                        Brush.horizontalGradient(listOf(AccentDeep, AccentPrimary, AccentBright))
                    } else {
                        SolidColor(SurfaceOverlay)
                    }
                ),
            contentAlignment = Alignment.Center,
        ) {
            if (loading) {
                CircularProgressIndicator(
                    Modifier.size(22.dp),
                    strokeWidth = 2.dp,
                    color = Color.White,
                )
            } else {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        text,
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.SemiBold,
                        color = if (enabled) Color.White else TextMuted,
                    )
                    Spacer(Modifier.width(10.dp))
                    Icon(
                        Icons.AutoMirrored.Filled.ArrowForward,
                        null,
                        tint = if (enabled) Color.White else TextMuted,
                        modifier = Modifier.size(20.dp),
                    )
                }
            }
        }
    }
}

/**
 * How much of a password there is, in five steps.
 *
 * Length first and hardest: under the minimum nothing else can lift it above
 * one bar, because a seven-character password with a symbol in it is still a
 * seven-character password.
 */
fun passwordScore(password: String): Int {
    if (password.isEmpty()) return 0

    var score = 1
    if (password.length >= 10) score++
    if (password.length >= 14) score++
    if (password.any { it.isDigit() } && password.any { it.isLetter() }) score++
    if (password.any { !it.isLetterOrDigit() }) score++

    return if (password.length < MIN_PASSWORD) 1 else score.coerceAtMost(5)
}

/** The shortest password the server will take. */
const val MIN_PASSWORD = 8

/** The five-segment meter under the password field, and what it says. */
@Composable
fun PasswordStrength(password: String, modifier: Modifier = Modifier) {
    val score = passwordScore(password)

    val colour = when {
        score <= 1 -> StatusError
        score <= 3 -> StatusWarning
        else -> StatusSuccess
    }

    Column(modifier.fillMaxWidth()) {
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            repeat(5) { index ->
                val filled = index < score
                val alpha by animateFloatAsState(if (filled) 1f else 0.18f, label = "strength")

                Box(
                    Modifier
                        .weight(1f)
                        .height(4.dp)
                        .clip(RoundedCornerShape(2.dp))
                        .background((if (filled) colour else Color.White).copy(alpha = alpha))
                )
            }
        }

        Spacer(Modifier.height(8.dp))

        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(
                Icons.Outlined.Shield,
                null,
                tint = if (password.isEmpty()) TextMuted else colour,
                modifier = Modifier.size(14.dp),
            )
            Spacer(Modifier.width(6.dp))
            Text(
                // Before anything is typed the rule is the useful thing to
                // say; after that, how it is doing against the rule.
                if (password.isEmpty()) {
                    stringResource(R.string.auth_password_min)
                } else {
                    stringResource(
                        when (score) {
                            1 -> R.string.auth_strength_weak
                            2, 3 -> R.string.auth_strength_fair
                            4 -> R.string.auth_strength_good
                            else -> R.string.auth_strength_strong
                        }
                    )
                },
                style = MaterialTheme.typography.labelMedium,
                color = if (password.isEmpty()) TextMuted else colour,
            )
        }
    }
}

/**
 * The line at the bottom that leads to the other screen.
 *
 * The rule under the link is from the design; it is what makes it read as the
 * way out of this page rather than a caption.
 */
@Composable
fun AuthSwitch(question: String, action: String, onClick: () -> Unit, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(question, style = MaterialTheme.typography.bodyMedium, color = TextSecondary)
            Spacer(Modifier.width(6.dp))
            TextButton(onClick = onClick, contentPadding = PaddingValues(horizontal = 4.dp)) {
                Text(
                    action,
                    style = MaterialTheme.typography.bodyMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = AccentBright,
                )
            }
        }

        Box(
            Modifier
                .width(56.dp)
                .height(2.dp)
                .clip(RoundedCornerShape(1.dp))
                .background(AccentPrimary.copy(alpha = 0.7f))
        )
    }
}
