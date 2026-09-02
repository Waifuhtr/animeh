package com.animeh.app.ui.screens.detail

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material.icons.outlined.Flag
import androidx.compose.material.icons.outlined.ThumbDown
import androidx.compose.material.icons.outlined.ThumbUp
import androidx.compose.material.icons.outlined.VisibilityOff
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.data.remote.dto.ReviewDto
import com.animeh.app.ui.theme.StatusWarning
import com.animeh.app.ui.theme.SurfaceCard
import com.animeh.app.ui.theme.SurfaceOverlay
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary
import java.util.Locale

/**
 * What people thought, and what people thought of what they thought.
 *
 * A score out of ten is the part that feeds recommendations; the prose is
 * optional, because plenty of people will rate a series without wanting to
 * write about it, and demanding a paragraph mostly produces empty ones.
 */
@Composable
fun ReviewSection(
    reviews: List<ReviewDto>,
    mine: ReviewDto?,
    average: Double,
    ratingCount: Int,
    signedIn: Boolean,
    onSubmit: (score: Int, body: String, spoiler: Boolean) -> Unit,
    onDelete: (ReviewDto) -> Unit,
    onVote: (ReviewDto, Int) -> Unit,
    onReport: (ReviewDto, String, String) -> Unit,
    onSignIn: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(modifier.fillMaxWidth().padding(horizontal = 16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                stringResource(R.string.reviews_title),
                style = MaterialTheme.typography.headlineSmall,
                modifier = Modifier.weight(1f),
            )

            if (ratingCount > 0) {
                Icon(Icons.Filled.Star, null, Modifier.size(16.dp), tint = StatusWarning)
                Spacer(Modifier.width(5.dp))
                Text(
                    stringResource(
                        R.string.reviews_average,
                        String.format(Locale.getDefault(), "%.1f", average),
                        ratingCount,
                    ),
                    style = MaterialTheme.typography.labelMedium,
                    color = TextSecondary,
                )
            } else {
                Text(
                    stringResource(R.string.reviews_none_yet),
                    style = MaterialTheme.typography.labelMedium,
                    color = TextMuted,
                )
            }
        }

        Spacer(Modifier.height(12.dp))

        if (signedIn) {
            ReviewComposer(existing = mine, onSubmit = onSubmit, onDelete = onDelete)
        } else {
            TextButton(onClick = onSignIn) { Text(stringResource(R.string.reviews_sign_in)) }
        }

        Spacer(Modifier.height(8.dp))

        val others = reviews.filterNot { it.mine }

        if (others.isEmpty() && mine == null) {
            Text(
                stringResource(R.string.reviews_empty),
                style = MaterialTheme.typography.bodyMedium,
                color = TextMuted,
                modifier = Modifier.padding(vertical = 16.dp),
            )
        }

        others.forEach { review ->
            ReviewRow(
                review = review,
                onVote = { vote -> onVote(review, vote) },
                onReport = { reason, note -> onReport(review, reason, note) },
                canVote = signedIn,
            )
            Spacer(Modifier.height(10.dp))
        }
    }
}

/**
 * The form for one's own review, which doubles as the editor for it.
 *
 * Opening it filled in when a review already exists is what makes the second
 * submission an edit rather than a duplicate — the server enforces one per
 * person per work, so an empty form would just overwrite silently.
 */
@Composable
private fun ReviewComposer(
    existing: ReviewDto?,
    onSubmit: (score: Int, body: String, spoiler: Boolean) -> Unit,
    onDelete: (ReviewDto) -> Unit,
) {
    var expanded by remember(existing?.id) { mutableStateOf(false) }
    var score by remember(existing?.id) { mutableIntStateOf(existing?.score ?: 0) }
    var body by remember(existing?.id) { mutableStateOf(existing?.body.orEmpty()) }
    var spoiler by remember(existing?.id) { mutableStateOf(existing?.spoiler ?: false) }

    Surface(color = SurfaceCard, shape = RoundedCornerShape(14.dp)) {
        Column(Modifier.fillMaxWidth().padding(14.dp)) {
            if (!expanded) {
                TextButton(onClick = { expanded = true }) {
                    Text(
                        stringResource(
                            if (existing == null) R.string.reviews_write else R.string.reviews_edit
                        )
                    )
                }
                return@Column
            }

            Text(
                stringResource(R.string.reviews_score, score),
                style = MaterialTheme.typography.titleSmall,
            )

            Spacer(Modifier.height(8.dp))

            // Ten taps rather than a slider: a slider makes picking an exact
            // number fiddly on a phone, and the number is the whole point.
            Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                (1..10).forEach { value ->
                    val chosen = value <= score
                    Box(
                        Modifier
                            .weight(1f)
                            .height(34.dp)
                            .clip(RoundedCornerShape(8.dp))
                            .background(if (chosen) MaterialTheme.colorScheme.primary else SurfaceOverlay)
                            .clickable { score = value },
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(
                            "$value",
                            style = MaterialTheme.typography.labelSmall,
                            color = if (chosen) MaterialTheme.colorScheme.onPrimary else TextSecondary,
                        )
                    }
                }
            }

            Spacer(Modifier.height(12.dp))

            OutlinedTextField(
                value = body,
                onValueChange = { body = it },
                placeholder = { Text(stringResource(R.string.reviews_body_hint)) },
                minLines = 3,
                modifier = Modifier.fillMaxWidth(),
            )

            Row(verticalAlignment = Alignment.CenterVertically) {
                Checkbox(checked = spoiler, onCheckedChange = { spoiler = it })
                Text(
                    stringResource(R.string.reviews_spoiler_mark),
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.clickable { spoiler = !spoiler },
                )
            }

            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.End,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                if (existing != null) {
                    IconButton(onClick = { onDelete(existing); expanded = false }) {
                        Icon(
                            Icons.Outlined.Delete,
                            stringResource(R.string.reviews_delete),
                            tint = MaterialTheme.colorScheme.error,
                        )
                    }
                    Spacer(Modifier.width(4.dp))
                }

                TextButton(onClick = { expanded = false }) {
                    Text(stringResource(R.string.cancel))
                }

                Spacer(Modifier.width(4.dp))

                Button(
                    onClick = { onSubmit(score, body, spoiler); expanded = false },
                    enabled = score > 0,
                ) {
                    Text(stringResource(R.string.save))
                }
            }
        }
    }
}

/** Somebody else's review, with the spoiler kept behind a tap. */
@Composable
private fun ReviewRow(
    review: ReviewDto,
    onVote: (Int) -> Unit,
    onReport: (reason: String, note: String) -> Unit,
    canVote: Boolean,
) {
    // Reset when the row is recycled onto a different review, so one revealed
    // spoiler does not uncover the next.
    var revealed by remember(review.id) { mutableStateOf(false) }
    var reporting by remember(review.id) { mutableStateOf(false) }

    if (reporting) {
        ReportDialog(
            onDismiss = { reporting = false },
            onSubmit = { reason, note ->
                reporting = false
                onReport(reason, note)
            },
        )
    }

    Surface(color = SurfaceCard, shape = RoundedCornerShape(14.dp)) {
        Column(Modifier.fillMaxWidth().padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                AsyncImage(
                    model = review.authorAvatar,
                    contentDescription = null,
                    contentScale = ContentScale.Crop,
                    modifier = Modifier
                        .size(32.dp)
                        .clip(CircleShape)
                        .background(SurfaceOverlay),
                )

                Spacer(Modifier.width(10.dp))

                Text(
                    review.author,
                    style = MaterialTheme.typography.titleSmall,
                    modifier = Modifier.weight(1f),
                )

                Icon(Icons.Filled.Star, null, Modifier.size(14.dp), tint = StatusWarning)
                Spacer(Modifier.width(4.dp))
                Text("${review.score}/10", style = MaterialTheme.typography.labelMedium)
            }

            if (review.body.isNotBlank()) {
                Spacer(Modifier.height(10.dp))

                if (review.spoiler && !revealed) {
                    Surface(
                        color = SurfaceOverlay,
                        shape = RoundedCornerShape(10.dp),
                        modifier = Modifier
                            .fillMaxWidth()
                            .clickable { revealed = true },
                    ) {
                        Row(
                            Modifier.padding(14.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                Icons.Outlined.VisibilityOff,
                                null,
                                Modifier.size(18.dp),
                                tint = TextMuted,
                            )
                            Spacer(Modifier.width(10.dp))
                            Text(
                                stringResource(R.string.reviews_spoiler_hidden),
                                style = MaterialTheme.typography.bodySmall,
                                color = TextMuted,
                                textAlign = TextAlign.Start,
                            )
                        }
                    }
                } else {
                    Text(review.body, style = MaterialTheme.typography.bodyMedium)
                }
            }

            Spacer(Modifier.height(10.dp))

            Row(verticalAlignment = Alignment.CenterVertically) {
                VoteButton(
                    icon = Icons.Outlined.ThumbUp,
                    label = stringResource(R.string.reviews_agree),
                    count = review.upVotes,
                    active = review.myVote > 0,
                    enabled = canVote,
                    onClick = { onVote(1) },
                )

                Spacer(Modifier.width(12.dp))

                VoteButton(
                    icon = Icons.Outlined.ThumbDown,
                    label = stringResource(R.string.reviews_disagree),
                    count = review.downVotes,
                    active = review.myVote < 0,
                    enabled = canVote,
                    onClick = { onVote(-1) },
                )

                Spacer(Modifier.weight(1f))

                // Only signed in: a report has to be attributable, or the
                // queue fills with anonymous noise nobody can weigh.
                if (canVote) {
                    IconButton(
                        onClick = { reporting = true },
                        modifier = Modifier.size(32.dp),
                    ) {
                        Icon(
                            Icons.Outlined.Flag,
                            contentDescription = stringResource(R.string.report_action),
                            modifier = Modifier.size(16.dp),
                            tint = TextMuted,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun VoteButton(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    label: String,
    count: Int,
    active: Boolean,
    enabled: Boolean,
    onClick: () -> Unit,
) {
    val tint = if (active) MaterialTheme.colorScheme.primary else TextMuted

    Row(
        Modifier
            .clip(RoundedCornerShape(8.dp))
            .clickable(enabled = enabled, onClick = onClick)
            .padding(horizontal = 8.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, label, Modifier.size(16.dp), tint = tint)
        Spacer(Modifier.width(6.dp))
        Text("$count", style = MaterialTheme.typography.labelMedium, color = tint)
    }
}

/**
 * Pick a reason, and write one when none of them fits.
 *
 * The fixed reasons are what make the queue sortable — "six people reported
 * this for spam" is only countable if spam is a value rather than a sentence.
 * `other` exists because a closed list never covers everything, and it is the
 * one option that insists on words.
 */
@Composable
private fun ReportDialog(
    onDismiss: () -> Unit,
    onSubmit: (reason: String, note: String) -> Unit,
) {
    var reason by remember { mutableStateOf(REPORT_REASONS.first()) }
    var note by remember { mutableStateOf("") }

    val needsNote = reason == "other"
    val canSend = !needsNote || note.isNotBlank()

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.report_title)) },
        text = {
            Column {
                REPORT_REASONS.forEach { value ->
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(8.dp))
                            .clickable { reason = value }
                            .padding(vertical = 6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        RadioButton(selected = reason == value, onClick = { reason = value })
                        Spacer(Modifier.width(6.dp))
                        Text(
                            stringResource(reasonLabel(value)),
                            style = MaterialTheme.typography.bodyMedium,
                        )
                    }
                }

                if (needsNote) {
                    Spacer(Modifier.height(8.dp))
                    OutlinedTextField(
                        value = note,
                        onValueChange = { note = it.take(REPORT_NOTE_MAX) },
                        placeholder = { Text(stringResource(R.string.report_note_hint)) },
                        minLines = 2,
                        maxLines = 4,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Text(
                        stringResource(R.string.report_note_required),
                        style = MaterialTheme.typography.labelSmall,
                        color = TextMuted,
                        modifier = Modifier.padding(top = 4.dp),
                    )
                }
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onSubmit(reason, note) },
                enabled = canSend,
            ) {
                Text(stringResource(R.string.report_action))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.cancel)) }
        },
    )
}

/** The reasons offered, in the order they are shown. Mirrors the server. */
private val REPORT_REASONS = listOf("spam", "spoiler", "abuse", "offtopic", "other")

/** As much as the server keeps of a note; more would be silently truncated. */
private const val REPORT_NOTE_MAX = 500

private fun reasonLabel(value: String): Int = when (value) {
    "spam" -> R.string.report_reason_spam
    "spoiler" -> R.string.report_reason_spoiler
    "abuse" -> R.string.report_reason_abuse
    "offtopic" -> R.string.report_reason_offtopic
    else -> R.string.report_reason_other
}
