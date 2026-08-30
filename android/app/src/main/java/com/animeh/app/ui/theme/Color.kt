package com.animeh.app.ui.theme

import androidx.compose.ui.graphics.Color

/**
 * The palette from the concept design: near-black grounds, a purple accent,
 * and enough steps between them to build depth without borders.
 *
 * Named by role rather than by hue, so a later change to the accent is one
 * edit here and not a search through every screen.
 */

// Grounds, darkest to lightest. The steps are close together on purpose: a
// card lifts off the page by being one step lighter, not by having an outline.
val SurfaceBase = Color(0xFF0B0A10)
val SurfaceRaised = Color(0xFF14121C)
val SurfaceCard = Color(0xFF1B1926)
val SurfaceOverlay = Color(0xFF241F33)
val SurfaceScrim = Color(0xCC08070C)

// Accent. Primary is the interactive purple; the container is what a selected
// chip or a progress track uses so the accent is not shouting everywhere.
val AccentPrimary = Color(0xFF8B5CF6)
val AccentBright = Color(0xFFA78BFA)
val AccentDeep = Color(0xFF6D28D9)
val AccentContainer = Color(0xFF2E1F52)

// Text. `Muted` is the lowest step that still clears 4.5:1 on SurfaceCard;
// anything dimmer than this is for decoration, never for content.
val TextPrimary = Color(0xFFF5F3FF)
val TextSecondary = Color(0xFFB9B4CC)
val TextMuted = Color(0xFF8A8499)

// Status.
val StatusSuccess = Color(0xFF34D399)
val StatusWarning = Color(0xFFFBBF24)
val StatusError = Color(0xFFF87171)
val StatusInfo = Color(0xFF60A5FA)

// Airing badges on a card.
val BadgeAiring = Color(0xFF34D399)
val BadgeFinished = Color(0xFF8A8499)
val BadgeUpcoming = Color(0xFF60A5FA)

// Dividers, kept low enough to read as a seam rather than a line.
val DividerSubtle = Color(0x1AFFFFFF)
