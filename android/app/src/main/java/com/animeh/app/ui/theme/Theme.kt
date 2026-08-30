package com.animeh.app.ui.theme

import android.app.Activity
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Shapes
import androidx.compose.ui.unit.dp
import androidx.core.view.WindowCompat

/**
 * The app's theme.
 *
 * One colour scheme, dark, in both system modes. The design is a dark product
 * (§3) and a "light mode" assembled by inverting these values would be a
 * different design nobody drew — so the light scheme here is the same one,
 * deliberately, rather than a half-finished second theme.
 *
 * Dynamic colour is off for the same reason: an anime poster wall against
 * whatever purple the wallpaper produced is not the design either.
 */
private val AnimehColorScheme = darkColorScheme(
    primary = AccentPrimary,
    onPrimary = TextPrimary,
    primaryContainer = AccentContainer,
    onPrimaryContainer = AccentBright,
    secondary = AccentBright,
    onSecondary = SurfaceBase,
    secondaryContainer = SurfaceOverlay,
    onSecondaryContainer = TextPrimary,
    tertiary = StatusInfo,
    background = SurfaceBase,
    onBackground = TextPrimary,
    surface = SurfaceRaised,
    onSurface = TextPrimary,
    surfaceVariant = SurfaceCard,
    onSurfaceVariant = TextSecondary,
    surfaceContainer = SurfaceCard,
    surfaceContainerHigh = SurfaceOverlay,
    surfaceContainerHighest = SurfaceOverlay,
    outline = DividerSubtle,
    outlineVariant = DividerSubtle,
    error = StatusError,
    onError = SurfaceBase,
    scrim = SurfaceScrim,
)

/**
 * Rounded corners throughout, sized so a poster and a chip read as the same
 * family without the poster looking like a pill.
 */
val AnimehShapes = Shapes(
    extraSmall = RoundedCornerShape(6.dp),
    small = RoundedCornerShape(10.dp),
    medium = RoundedCornerShape(14.dp),
    large = RoundedCornerShape(20.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

/** The poster shape, used by every card that shows artwork. */
val PosterShape: Shape = RoundedCornerShape(12.dp)

@Composable
fun AnimehTheme(
    // Accepted and ignored on purpose: the parameter documents that the choice
    // was considered rather than overlooked.
    @Suppress("UNUSED_PARAMETER") darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            val window = (view.context as Activity).window
            // Content goes under the bars; every screen applies its own
            // insets, which is what lets the player and the hero banner reach
            // the edges.
            WindowCompat.setDecorFitsSystemWindows(window, false)
            WindowCompat.getInsetsController(window, view).isAppearanceLightStatusBars = false
        }
    }

    MaterialTheme(
        colorScheme = AnimehColorScheme,
        typography = AnimehTypography,
        shapes = AnimehShapes,
        content = content,
    )
}

/** Kept so a light platform theme is never silently substituted. */
internal val UnusedLightScheme = lightColorScheme(primary = AccentPrimary)
