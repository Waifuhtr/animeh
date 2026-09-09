package com.animeh.app.ui.theme

import androidx.compose.runtime.Immutable
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color

/**
 * The colour a profile is dressed in.
 *
 * The palette lives here and the *choice* lives on the server. That split is
 * deliberate in both directions: a colour is a design decision and belongs in
 * the design, so the server never stores a hex value it cannot later adjust;
 * but the choice has to be stored somewhere everyone can read, because the
 * whole point is that other people see it.
 *
 * The slugs match [Animeh\Support\ProfileTheme::THEMES] exactly. A slug the
 * app does not know falls back to the default rather than failing — an app one
 * version behind the server should show the wrong colour, not a blank screen.
 *
 * Every one of them is free. Points are for frames; charging for a colour
 * would only mean half the profiles look identical.
 */
@Immutable
data class ProfileTheme(
    val slug: String,
    val label: String,
    /** The one that carries: rings, highlights, the active tab. */
    val accent: Color,
    /** Where the banner gradient ends. */
    val deep: Color,
) {
    /** The banner behind a profile header. */
    val banner: Brush
        get() = Brush.linearGradient(
            listOf(
                accent.copy(alpha = 0.55f),
                deep.copy(alpha = 0.30f),
                SurfaceBase,
            )
        )

    /** A soft wash for a card that belongs to this profile. */
    val wash: Color get() = accent.copy(alpha = 0.14f)
}

/**
 * The palette, in the order it is shown.
 *
 * Twelve, which fills three rows of four on every phone width without a row
 * that is half empty.
 */
val ProfileThemes: List<ProfileTheme> = listOf(
    ProfileTheme("amethyst", "Ametist", Color(0xFF8B5CF6), Color(0xFF4C1D95)),
    ProfileTheme("sakura", "Sakura", Color(0xFFF472B6), Color(0xFF9D174D)),
    ProfileTheme("ocean", "Okyanus", Color(0xFF38BDF8), Color(0xFF0C4A6E)),
    ProfileTheme("jade", "Yeşim", Color(0xFF34D399), Color(0xFF065F46)),
    ProfileTheme("ember", "Kor", Color(0xFFFB7185), Color(0xFF7F1D1D)),
    ProfileTheme("gold", "Altın", Color(0xFFFBBF24), Color(0xFF78350F)),
    ProfileTheme("rose", "Gül", Color(0xFFFDA4AF), Color(0xFF881337)),
    ProfileTheme("midnight", "Gece Yarısı", Color(0xFF60A5FA), Color(0xFF1E3A8A)),
    ProfileTheme("lagoon", "Lagün", Color(0xFF2DD4BF), Color(0xFF134E4A)),
    ProfileTheme("sunset", "Gün Batımı", Color(0xFFFB923C), Color(0xFF7C2D12)),
    ProfileTheme("mint", "Nane", Color(0xFFA3E635), Color(0xFF3F6212)),
    ProfileTheme("violet", "Menekşe", Color(0xFFC084FC), Color(0xFF581C87)),
)

/** The palette as a map, built once rather than searched on every recomposition. */
private val byslug: Map<String, ProfileTheme> = ProfileThemes.associateBy { it.slug }

/**
 * The theme for a stored slug, or the default for anything unrecognised.
 *
 * Never throws and never returns null: this runs while drawing somebody's
 * profile, and a colour is not worth a crash.
 */
fun profileTheme(slug: String?): ProfileTheme =
    byslug[slug.orEmpty()] ?: ProfileThemes.first()

/** The colours a rarity paints a shop card's edge. */
fun rarityColor(rarity: String): Color = when (rarity) {
    "legendary" -> Color(0xFFFBBF24)
    "epic" -> Color(0xFFC084FC)
    "rare" -> Color(0xFF38BDF8)
    else -> Color(0xFF8A8499)
}

/** What a rarity is called on screen. */
fun rarityLabel(rarity: String): String = when (rarity) {
    "legendary" -> "Efsanevi"
    "epic" -> "Destansı"
    "rare" -> "Nadir"
    else -> "Standart"
}
