package com.animeh.app.player.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.animeh.app.R
import com.animeh.app.player.ads.AdBreakState

/**
 * What is drawn over an ad.
 *
 * Deliberately little. The viewer is being held at an interruption they did
 * not ask for, so the screen's whole job is to say what this is, how long it
 * lasts, and how to get out of it — and then get out of the way of the one
 * thing the advertiser paid for, which is the picture.
 *
 * Every element here is positioned away from the middle for that reason.
 *
 * The normal controls are not drawn at all during a break: a seek bar over an
 * ad is an invitation to scrub past it, and a play/pause button that pauses
 * an ad while its timer runs is a bug the viewer would be right to report.
 */
@Composable
fun AdOverlay(
    state: AdBreakState,
    onSkip: () -> Unit,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    when (state) {
        is AdBreakState.Idle -> Unit

        is AdBreakState.Loading ->
            Box(modifier.fillMaxSize().background(Color.Black), Alignment.Center) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    CircularProgressIndicator(color = Color.White)
                    Text(
                        text = stringResource(R.string.ad_loading),
                        color = Color.White.copy(alpha = 0.75f),
                        fontSize = 13.sp,
                        modifier = Modifier.padding(top = 16.dp),
                    )
                }
            }

        is AdBreakState.Showing -> Showing(state, onSkip, onClick, modifier)
    }
}

@Composable
private fun Showing(
    state: AdBreakState.Showing,
    onSkip: () -> Unit,
    onClick: () -> Unit,
    modifier: Modifier,
) {
    Box(
        modifier
            .fillMaxSize()
            // The whole picture is the click target, which is what an
            // advertiser buys and what every other player does. The skip
            // button sits above it and takes its own taps.
            .clickable(onClick = onClick)
    ) {
        Row(
            Modifier
                .align(Alignment.TopStart)
                .padding(16.dp)
                .clip(RoundedCornerShape(6.dp))
                .background(Color.Black.copy(alpha = 0.6f))
                .padding(horizontal = 10.dp, vertical = 5.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                text = stringResource(R.string.ad_label),
                color = Color.White,
                fontSize = 12.sp,
                fontWeight = FontWeight.SemiBold,
            )

            // The countdown is the part that makes an interruption bearable:
            // an ad with a visible end is waited out, one without is closed.
            if (state.remainingMs > 0) {
                Text(
                    text = " · " + stringResource(
                        R.string.ad_remaining,
                        ((state.remainingMs + 999) / 1000).toInt(),
                    ),
                    color = Color.White.copy(alpha = 0.8f),
                    fontSize = 12.sp,
                )
            }
        }

        state.ad.cta?.let { cta ->
            Column(
                Modifier
                    .align(Alignment.BottomStart)
                    .padding(16.dp)
                    .clip(RoundedCornerShape(8.dp))
                    .background(Color.Black.copy(alpha = 0.6f))
                    .clickable(onClick = onClick)
                    .padding(horizontal = 14.dp, vertical = 8.dp),
                verticalArrangement = Arrangement.spacedBy(2.dp),
            ) {
                Text(cta.text, color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
                Text(cta.displayUrl, color = Color.White.copy(alpha = 0.7f), fontSize = 11.sp)
            }
        }

        if (state.skipAtMs != null) {
            SkipButton(state, onSkip, Modifier.align(Alignment.BottomEnd).padding(16.dp))
        }
    }
}

/**
 * The way out, and the wait for it.
 *
 * Drawn before it works rather than appearing when it does. A button that
 * materialises without warning is one the viewer has to notice; a countdown
 * they can watch is one they wait for.
 */
@Composable
private fun SkipButton(state: AdBreakState.Showing, onSkip: () -> Unit, modifier: Modifier) {
    val ready = state.canSkip

    Box(
        modifier
            .clip(RoundedCornerShape(8.dp))
            .background(if (ready) Color.White.copy(alpha = 0.92f) else Color.Black.copy(alpha = 0.6f))
            // Only clickable once it means something: a tap that does nothing
            // teaches the viewer the button is broken.
            .then(if (ready) Modifier.clickable(onClick = onSkip) else Modifier)
            .padding(horizontal = 16.dp, vertical = 10.dp)
    ) {
        Text(
            text = if (ready) {
                stringResource(R.string.ad_skip)
            } else {
                stringResource(R.string.ad_skip_in, state.skipInSeconds)
            },
            color = if (ready) Color.Black else Color.White.copy(alpha = 0.85f),
            fontSize = 14.sp,
            fontWeight = if (ready) FontWeight.SemiBold else FontWeight.Normal,
        )
    }
}
