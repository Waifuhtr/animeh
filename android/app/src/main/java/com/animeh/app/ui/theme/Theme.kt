package com.animeh.app.ui.theme

import android.app.Activity
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.graphics.compositeOver
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
 *
 * What the viewer *can* change is the accent — the purple that carries
 * buttons, selections and highlights. Only the accent, and only from a
 * palette that was drawn: the surfaces stay where they are, so a chosen
 * colour changes the app's character without any screen becoming unreadable.
 * The palette is the one profiles already use, so the two never drift into
 * two sets of greens that are almost the same.
 */
private fun schemeFor(accent: ProfileTheme) = darkColorScheme(
    primary = accent.accent,
    onPrimary = TextPrimary,
    // Derived rather than listed per palette: the container is the accent
    // laid over the app's own surface, which is what keeps a selected chip
    // looking like this product in every colour rather than like twelve.
    primaryContainer = accent.deep.copy(alpha = 0.55f).compositeOver(SurfaceCard),
    onPrimaryContainer = accent.accent,
    secondary = accent.accent,
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
    /**
     * The palette the viewer picked.
     *
     * Defaulted so that a preview, a dialog drawn outside the app's own
     * composition, or a screen added later without knowing about this still
     * gets the product's own colour rather than nothing.
     */
    accent: ProfileTheme = ProfileThemes.first(),
    content: @Composable () -> Unit,
) {
    val scheme = remember(accent.slug) { schemeFor(accent) }

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
        colorScheme = scheme,
        typography = AnimehTypography,
        shapes = AnimehShapes,
    ) {
        // Material's own default for `LocalContentColor` is black, and it
        // only stops being black inside a `Surface` — which `Scaffold`
        // happens to provide and a hand-built screen does not. On a dark
        // product that means any `Text` written without a colour is
        // invisible, on whichever screen forgot the wrapper, and nowhere
        // else. Setting it here makes "no colour given" mean the theme's
        // text colour everywhere; a `Surface` still overrides it for its
        // own subtree, so nothing that reads correctly today changes.
        CompositionLocalProvider(LocalContentColor provides TextPrimary, content = content)
    }
}

/** Kept so a light platform theme is never silently substituted. */
internal val UnusedLightScheme = lightColorScheme(primary = AccentPrimary)
