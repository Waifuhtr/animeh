package com.animeh.app.ui.theme

import androidx.compose.material3.Typography
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.LineHeightStyle
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.sp

/**
 * Type scale.
 *
 * The system font family rather than a bundled one: an anime catalog carries
 * Japanese, Korean and Turkish titles in the same list, and the platform font
 * has the coverage for all three. A bundled Latin face would render half the
 * catalog in fallback glyphs that do not match.
 */
private val Sans = FontFamily.Default

/**
 * One style, with the line box left alone.
 *
 * Compose trims the top of the first line and the bottom of the last down to
 * the line height by default. With line heights this tight that cuts the dot
 * off Turkish İ — which is exactly the "İzle" on the slider's own button — and
 * would do the same to Ğ and to Japanese glyphs that sit taller than Latin.
 * `Trim.None` keeps the font's own ascent and descent, and the line heights
 * below are a little looser so the extra headroom has somewhere to go.
 */
private fun style(weight: FontWeight, size: Int, line: Int) = TextStyle(
    fontFamily = Sans,
    fontWeight = weight,
    fontSize = size.sp,
    lineHeight = line.sp,
    lineHeightStyle = LineHeightStyle(
        alignment = LineHeightStyle.Alignment.Center,
        trim = LineHeightStyle.Trim.None,
    ),
)

val AnimehTypography = Typography(
    displayLarge = style(FontWeight.Bold, 34, 42),
    displayMedium = style(FontWeight.Bold, 28, 36),

    headlineLarge = style(FontWeight.Bold, 24, 32),
    headlineMedium = style(FontWeight.SemiBold, 20, 28),
    headlineSmall = style(FontWeight.SemiBold, 18, 26),

    titleLarge = style(FontWeight.SemiBold, 17, 24),
    titleMedium = style(FontWeight.SemiBold, 15, 22),
    titleSmall = style(FontWeight.Medium, 13, 20),

    bodyLarge = style(FontWeight.Normal, 15, 23),
    bodyMedium = style(FontWeight.Normal, 13, 20),
    bodySmall = style(FontWeight.Normal, 12, 18),

    labelLarge = style(FontWeight.SemiBold, 14, 20),
    labelMedium = style(FontWeight.Medium, 12, 18),
    labelSmall = style(FontWeight.Medium, 11, 16),
)

/** Two lines and an ellipsis: the shape every card title uses. */
const val TITLE_MAX_LINES = 2
val TitleOverflow = TextOverflow.Ellipsis
