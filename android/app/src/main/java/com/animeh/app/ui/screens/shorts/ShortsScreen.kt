package com.animeh.app.ui.screens.shorts

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.pager.VerticalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.*
import androidx.compose.material.icons.outlined.FavoriteBorder
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.ui.AspectRatioFrameLayout
import androidx.media3.ui.PlayerView
import androidx.compose.ui.viewinterop.AndroidView
import coil.compose.AsyncImage
import com.animeh.app.R
import com.animeh.app.data.remote.dto.ShortCommentDto
import com.animeh.app.data.remote.dto.ShortDto
import com.animeh.app.data.repository.ShortsRepository
import com.animeh.app.ui.components.EmptyState
import com.animeh.app.ui.components.ErrorState
import com.animeh.app.ui.theme.AccentPrimary
import com.animeh.app.ui.theme.StatusError
import com.animeh.app.ui.theme.TextMuted
import com.animeh.app.ui.theme.TextSecondary

/**
 * AnimehTok's feed.
 *
 * One video per page, swiped vertically, over one engine holding the whole page
 * list as a playlist — one player rather than one per page, because a phone has
 * a small number of hardware decoders and somebody flicking through twenty
 * videos a minute would exhaust them.
 *
 * How quickly a swipe reaches its first frame is decided entirely in
 * [ShortsPlayer]; the reasoning lives there, next to the numbers.
 */
@Composable
fun ShortsScreen(
    onBack: () -> Unit,
    onOpenTag: (String) -> Unit,
    onOpenSound: (Long) -> Unit,
    onOpenCreator: (Long) -> Unit,
    onUpload: () -> Unit,
    onSearch: () -> Unit,
    viewModel: ShortsViewModel = hiltViewModel(),
) {
    // The app's own client, so media reuses the pool and the TLS session the
    // feed request itself just opened rather than starting from nothing.
    val httpClient = viewModel.httpClient
    val state by viewModel.state.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val snackbar = remember { SnackbarHostState() }

    // Everything about how fast a swipe reaches its first frame is in here.
    val engine = remember(httpClient) { ShortsPlayer(context, httpClient) }

    DisposableEffect(engine) {
        onDispose { engine.release() }
    }

    val pagerState = rememberPagerState(pageCount = { state.items.size })

    // The playlist follows the list. Keyed on the ids rather than the whole
    // list because a like arrives as a new ShortDto for one row, and rebuilding
    // the playlist for that would restart the video under the viewer's thumb.
    val ids = remember(state.items) { state.items.map { it.id } }

    LaunchedEffect(ids, state.tab) {
        engine.setFeed(state.items.map { it.videoUrl }, pagerState.currentPage)
    }

    LaunchedEffect(pagerState.currentPage, ids) {
        val page = pagerState.currentPage

        engine.playPage(page)

        state.items.getOrNull(page)?.let { viewModel.watched(it.id) }
        viewModel.loadMoreIfNeeded(page)
    }

    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            viewModel.messageShown()
        }
    }

    var commentsFor by remember { mutableStateOf<ShortDto?>(null) }

    // Pulled out so the branch below reads without a null assertion; the app
    // has none anywhere else and this is not the place to start.
    val failure = state.error

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        containerColor = Color.Black,
    ) { padding ->
        Box(Modifier.fillMaxSize().background(Color.Black)) {
            when {
                state.loading -> Box(Modifier.fillMaxSize(), Alignment.Center) {
                    CircularProgressIndicator(color = Color.White)
                }

                failure != null -> ErrorState(
                    error = failure,
                    onRetry = viewModel::retry,
                    modifier = Modifier.fillMaxSize(),
                )

                state.isEmpty -> EmptyState(
                    message = stringResource(
                        if (state.tab == ShortsRepository.TAB_FOLLOWING) R.string.tok_empty_following
                        else R.string.tok_empty
                    ),
                    modifier = Modifier.fillMaxSize(),
                )

                else -> VerticalPager(
                    state = pagerState,
                    modifier = Modifier.fillMaxSize(),
                    key = { index -> state.items.getOrNull(index)?.id ?: index },
                ) { page ->
                    val short = state.items.getOrNull(page) ?: return@VerticalPager

                    ShortPage(
                        short = short,
                        active = page == pagerState.currentPage,
                        player = engine.exo,
                        onLike = { viewModel.toggleLike(short) },
                        onSave = { viewModel.toggleSave(short) },
                        onFollow = { viewModel.toggleFollow(short) },
                        onComments = { commentsFor = short },
                        onTag = onOpenTag,
                        onSound = onOpenSound,
                        onCreator = onOpenCreator,
                        onDelete = { viewModel.delete(short) },
                    )
                }
            }

            TopBar(
                tab = state.tab,
                onTab = viewModel::setTab,
                onBack = onBack,
                onSearch = onSearch,
                onUpload = onUpload,
                modifier = Modifier.align(Alignment.TopCenter).padding(top = padding.calculateTopPadding()),
            )
        }
    }

    commentsFor?.let { short ->
        CommentsSheet(
            short = short,
            onDismiss = { commentsFor = null },
            onCounted = { viewModel.setCommentCount(short.id, it) },
        )
    }
}

/**
 * The two feeds and the way out.
 *
 * Drawn over the video rather than in a Scaffold's top bar: the video is the
 * screen, and a bar with its own background would cut a strip off it.
 */
@Composable
private fun TopBar(
    tab: String,
    onTab: (String) -> Unit,
    onBack: () -> Unit,
    onSearch: () -> Unit,
    onUpload: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(onClick = onBack) {
            Icon(Icons.AutoMirrored.Filled.ArrowBack, stringResource(R.string.back), tint = Color.White)
        }

        Row(
            modifier = Modifier.weight(1f),
            horizontalArrangement = Arrangement.Center,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            FeedTab(
                label = stringResource(R.string.tok_following),
                selected = tab == ShortsRepository.TAB_FOLLOWING,
                onClick = { onTab(ShortsRepository.TAB_FOLLOWING) },
            )
            Spacer(Modifier.width(18.dp))
            FeedTab(
                label = stringResource(R.string.tok_for_you),
                selected = tab == ShortsRepository.TAB_FOR_YOU,
                onClick = { onTab(ShortsRepository.TAB_FOR_YOU) },
            )
        }

        IconButton(onClick = onSearch) {
            Icon(Icons.Filled.Search, stringResource(R.string.search), tint = Color.White)
        }
        IconButton(onClick = onUpload) {
            Icon(Icons.Filled.AddCircleOutline, stringResource(R.string.tok_upload), tint = Color.White)
        }
    }
}

@Composable
private fun FeedTab(label: String, selected: Boolean, onClick: () -> Unit) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Text(
            label,
            color = if (selected) Color.White else Color.White.copy(alpha = 0.6f),
            fontWeight = if (selected) FontWeight.Bold else FontWeight.Normal,
            modifier = Modifier.clickable(onClick = onClick).padding(horizontal = 4.dp, vertical = 6.dp),
        )

        // The underline, not a chip: a chip on top of a video reads as part of
        // the video.
        Box(
            Modifier
                .width(22.dp)
                .height(2.dp)
                .background(if (selected) Color.White else Color.Transparent, CircleShape)
        )
    }
}

/**
 * One video, full screen, with everything drawn over it.
 */
@Composable
private fun ShortPage(
    short: ShortDto,
    active: Boolean,
    player: ExoPlayer,
    onLike: () -> Unit,
    onSave: () -> Unit,
    onFollow: () -> Unit,
    onComments: () -> Unit,
    onTag: (String) -> Unit,
    onSound: (Long) -> Unit,
    onCreator: (Long) -> Unit,
    onDelete: () -> Unit,
) {
    var paused by remember(short.id) { mutableStateOf(false) }
    var confirmDelete by remember(short.id) { mutableStateOf(false) }

    BoxWithConstraints(Modifier.fillMaxSize().background(Color.Black)) {
        // Measured rather than assumed: the rule below compares the video's
        // shape with the shape of the space it is going into, and that space is
        // this page.
        val resize = remember(short.id, short.fitMode, short.width, short.height, maxWidth, maxHeight) {
            resizeFor(short, maxWidth.value, maxHeight.value)
        }

        val fills = resize == AspectRatioFrameLayout.RESIZE_MODE_ZOOM

        // The cover, under the surface. It is what fills the frame while the
        // first bytes are still arriving, and it is why a video does not open
        // on black. It is laid in the same way the video will be, so the
        // picture does not jump when the first frame replaces it.
        if (short.coverUrl.isNotBlank()) {
            AsyncImage(
                model = short.coverUrl,
                contentDescription = null,
                contentScale = if (fills) ContentScale.Crop else ContentScale.Fit,
                modifier = Modifier.fillMaxSize(),
            )
        }

        if (active) {
            AndroidView(
                factory = { ctx ->
                    PlayerView(ctx).apply {
                        // A surface, not a UI: everything here is drawn in
                        // Compose over the top.
                        useController = false
                        setShowBuffering(PlayerView.SHOW_BUFFERING_NEVER)
                        setKeepContentOnPlayerReset(true)
                    }
                },
                // Both in update: a page's video can change shape under it when
                // the feed grows, and the factory only ever runs once.
                update = { view ->
                    view.player = player
                    view.resizeMode = resize
                },
                modifier = Modifier.fillMaxSize(),
            )
        }

        // Tap anywhere to pause. No control bar: there is nothing to scrub on
        // a fourteen-second video.
        Box(
            Modifier
                .fillMaxSize()
                .clickable {
                    paused = !paused
                    player.playWhenReady = !paused
                }
        )

        if (paused) {
            Icon(
                Icons.Filled.PlayArrow,
                contentDescription = null,
                tint = Color.White.copy(alpha = 0.8f),
                modifier = Modifier.align(Alignment.Center).size(72.dp),
            )
        }

        // A wash under the text, so a caption over a bright video stays
        // readable without a box around it.
        Box(
            Modifier
                .align(Alignment.BottomCenter)
                .fillMaxWidth()
                .height(280.dp)
                .background(
                    Brush.verticalGradient(
                        listOf(Color.Transparent, Color.Black.copy(alpha = 0.65f))
                    )
                )
        )

        Caption(
            short = short,
            onTag = onTag,
            onSound = onSound,
            onCreator = onCreator,
            modifier = Modifier
                .align(Alignment.BottomStart)
                .padding(start = 14.dp, end = 84.dp, bottom = 26.dp),
        )

        ActionRail(
            short = short,
            onLike = onLike,
            onSave = onSave,
            onComments = onComments,
            onFollow = onFollow,
            onCreator = onCreator,
            onSound = onSound,
            onDelete = { confirmDelete = true },
            modifier = Modifier.align(Alignment.BottomEnd).padding(end = 10.dp, bottom = 26.dp),
        )
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            title = { Text(stringResource(R.string.tok_delete)) },
            text = { Text(stringResource(R.string.tok_delete_confirm)) },
            confirmButton = {
                TextButton(
                    onClick = {
                        confirmDelete = false
                        onDelete()
                    }
                ) { Text(stringResource(R.string.delete), color = StatusError) }
            },
            dismissButton = {
                TextButton(onClick = { confirmDelete = false }) { Text(stringResource(R.string.cancel)) }
            },
        )
    }
}

/**
 * How a video should be laid into the frame it plays in.
 *
 * Filling the screen is right when a video very nearly fits: a 9:16 clip on a
 * 20:9 phone loses about a fifth of its width to the sides, and putting bars
 * around something that was shot to be watched full-bleed would be the wrong
 * answer. It is wrong when the two shapes disagree — a landscape video cropped
 * to a vertical screen loses most of its picture, which is the complaint this
 * exists to answer.
 *
 * So the question asked is how much would be lost rather than which way up the
 * video is, and [ShortsRepository.FIT_FILL] is the uploader saying to fill it
 * regardless. Nothing here changes the file: every pixel is still in the
 * bucket, and this only decides which of them reach the screen.
 *
 * Unknown dimensions fall to fitting. A video whose size the server never
 * recorded is more likely to be something unusual than something 9:16, and
 * fitting is the choice that cannot cut anything off.
 */
private fun resizeFor(short: ShortDto, frameWidth: Float, frameHeight: Float): Int {
    if (short.fitMode == ShortsRepository.FIT_FILL) {
        return AspectRatioFrameLayout.RESIZE_MODE_ZOOM
    }

    if (short.width <= 0 || short.height <= 0 || frameWidth <= 0f || frameHeight <= 0f) {
        return AspectRatioFrameLayout.RESIZE_MODE_FIT
    }

    val video = short.height.toFloat() / short.width
    val frame = frameHeight / frameWidth

    // What covering the frame would cut off, as a share of the video. The
    // shorter of the two ratios against the longer, whichever way round they
    // are: the overflow is in width when the video is the flatter of the two
    // and in height when it is the taller.
    val lost = 1f - minOf(video, frame) / maxOf(video, frame)

    return if (lost <= MAX_CROP) {
        AspectRatioFrameLayout.RESIZE_MODE_ZOOM
    } else {
        AspectRatioFrameLayout.RESIZE_MODE_FIT
    }
}

/**
 * How much of a video may be cut off to make it fill the screen.
 *
 * A quarter. Under it the crop reads as the video simply fitting the phone;
 * over it — a square clip on a tall screen, and every landscape one — what goes
 * missing is the picture.
 */
private const val MAX_CROP = 0.25f

/**
 * Who made it, what they said, and what it is playing.
 */
@Composable
private fun Caption(
    short: ShortDto,
    onTag: (String) -> Unit,
    onSound: (Long) -> Unit,
    onCreator: (Long) -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(modifier, verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(
            "@${short.creator.username.ifBlank { short.creator.displayName }}",
            color = Color.White,
            fontWeight = FontWeight.Bold,
            fontSize = 15.sp,
            modifier = Modifier.clickable { onCreator(short.creator.id) },
        )

        if (short.description.isNotBlank()) {
            TaggedText(
                text = short.description,
                tags = short.tags,
                onTag = onTag,
            )
        }

        short.sound?.let { sound ->
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.clickable { onSound(sound.id) },
            ) {
                Icon(
                    Icons.Filled.MusicNote,
                    contentDescription = stringResource(R.string.tok_sound),
                    tint = Color.White,
                    modifier = Modifier.size(15.dp),
                )
                Spacer(Modifier.width(6.dp))
                Text(
                    "${sound.title} · ${sound.author}",
                    color = Color.White,
                    fontSize = 13.sp,
                    maxLines = 1,
                )
            }
        }
    }
}

/**
 * A caption with its hashtags picked out and made tappable.
 *
 * The tag list comes from the server, which parses it with the same rule the
 * index uses — so what is underlined here is exactly what the tag page will
 * find, rather than a second guess made on the phone.
 */
@Composable
private fun TaggedText(
    text: String,
    tags: List<String>,
    onTag: (String) -> Unit,
) {
    if (tags.isEmpty()) {
        Text(text, color = Color.White, fontSize = 14.sp, maxLines = 3)
        return
    }

    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        // The caption without its tags, then the tags as their own row. A
        // tappable span inside a paragraph is a four-pixel target on a phone;
        // a row of chips is a real one.
        val plain = remember(text, tags) {
            tags.fold(text) { carry, tag -> carry.replace("#$tag", "") }
                .replace(Regex("\\s+"), " ")
                .trim()
        }

        if (plain.isNotBlank()) {
            Text(plain, color = Color.White, fontSize = 14.sp, maxLines = 3)
        }

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            tags.take(4).forEach { tag ->
                Text(
                    buildAnnotatedString {
                        withStyle(SpanStyle(fontWeight = FontWeight.SemiBold)) { append("#$tag") }
                    },
                    color = Color.White,
                    fontSize = 14.sp,
                    modifier = Modifier.clickable { onTag(tag) },
                )
            }
        }
    }
}

/**
 * The column of buttons down the right.
 */
@Composable
private fun ActionRail(
    short: ShortDto,
    onLike: () -> Unit,
    onSave: () -> Unit,
    onComments: () -> Unit,
    onFollow: () -> Unit,
    onCreator: (Long) -> Unit,
    onSound: (Long) -> Unit,
    onDelete: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier.width(64.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        Box(contentAlignment = Alignment.BottomCenter) {
            AsyncImage(
                model = short.creator.avatar,
                contentDescription = short.creator.displayName,
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .size(46.dp)
                    .clip(CircleShape)
                    .background(TextMuted)
                    .clickable { onCreator(short.creator.id) },
            )

            // The plus under the avatar, exactly where the thumb already is.
            // Hidden on your own video and once you already follow.
            if (!short.isMine && !short.following) {
                Icon(
                    Icons.Filled.AddCircle,
                    contentDescription = stringResource(R.string.tok_follow),
                    tint = AccentPrimary,
                    modifier = Modifier
                        .offset(y = 10.dp)
                        .size(22.dp)
                        .clip(CircleShape)
                        .background(Color.White, CircleShape)
                        .clickable(onClick = onFollow),
                )
            }
        }

        Spacer(Modifier.height(4.dp))

        RailAction(
            icon = if (short.liked) Icons.Filled.Favorite else Icons.Outlined.FavoriteBorder,
            tint = if (short.liked) StatusError else Color.White,
            label = count(short.likeCount),
            description = stringResource(R.string.tok_like),
            onClick = onLike,
        )

        RailAction(
            icon = Icons.Filled.Comment,
            tint = Color.White,
            label = count(short.commentCount),
            description = stringResource(R.string.tok_comment),
            onClick = onComments,
        )

        RailAction(
            icon = if (short.saved) Icons.Filled.Bookmark else Icons.Filled.BookmarkBorder,
            tint = if (short.saved) AccentPrimary else Color.White,
            label = count(short.saveCount),
            description = stringResource(R.string.tok_save),
            onClick = onSave,
        )

        if (short.isMine) {
            RailAction(
                icon = Icons.Filled.DeleteOutline,
                tint = Color.White,
                label = "",
                description = stringResource(R.string.tok_delete),
                onClick = onDelete,
            )
        }

        short.sound?.let { sound ->
            AsyncImage(
                model = sound.coverUrl,
                contentDescription = stringResource(R.string.tok_sound),
                contentScale = ContentScale.Crop,
                modifier = Modifier
                    .size(42.dp)
                    .clip(CircleShape)
                    .background(TextMuted)
                    .clickable { onSound(sound.id) },
            )
        }
    }
}

@Composable
private fun RailAction(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    tint: Color,
    label: String,
    description: String,
    onClick: () -> Unit,
) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        IconButton(onClick = onClick, modifier = Modifier.size(40.dp)) {
            Icon(icon, description, tint = tint, modifier = Modifier.size(32.dp))
        }

        if (label.isNotBlank()) {
            Text(label, color = Color.White, fontSize = 12.sp)
        }
    }
}

/**
 * A count as a viewer reads it.
 *
 * 12.4B rather than 12400: the exact number is not what anybody is looking at,
 * and five digits under an icon do not fit.
 */
private fun count(value: Long): String = when {
    value <= 0 -> ""
    value < 1_000 -> value.toString()
    value < 1_000_000 -> {
        val thousands = value / 100 / 10.0
        if (thousands >= 100) "${value / 1_000}B" else "${trim(thousands)}B"
    }
    else -> {
        val millions = value / 100_000 / 10.0
        "${trim(millions)}M"
    }
}

private fun trim(value: Double): String =
    if (value == value.toLong().toDouble()) value.toLong().toString() else String.format("%.1f", value)

/* ── Comments ────────────────────────────────────────────────────────── */

@Composable
private fun CommentsSheet(
    short: ShortDto,
    onDismiss: () -> Unit,
    onCounted: (Long) -> Unit,
    viewModel: ShortCommentsViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    LaunchedEffect(short.id) { viewModel.open(short.id) }

    var draft by rememberSaveable(short.id) { mutableStateOf("") }

    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(Modifier.fillMaxWidth().heightIn(min = 320.dp)) {
            Text(
                "${stringResource(R.string.tok_comments_title)} · ${state.total}",
                style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.SemiBold,
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
            )

            HorizontalDivider()

            Box(Modifier.weight(1f, fill = false)) {
                when {
                    state.loading -> Box(Modifier.fillMaxWidth().height(200.dp), Alignment.Center) {
                        CircularProgressIndicator()
                    }

                    state.comments.isEmpty() -> Box(
                        Modifier.fillMaxWidth().height(200.dp),
                        Alignment.Center,
                    ) {
                        Text(stringResource(R.string.tok_comment_empty), color = TextSecondary)
                    }

                    else -> LazyColumn(
                        contentPadding = PaddingValues(vertical = 8.dp),
                        modifier = Modifier.fillMaxWidth().heightIn(max = 440.dp),
                    ) {
                        items(state.comments, key = { it.id }) { comment ->
                            CommentRow(
                                comment = comment,
                                replies = state.replies[comment.id].orEmpty(),
                                expanded = comment.id in state.expanded,
                                onToggleReplies = { viewModel.toggleReplies(comment) },
                                onLike = viewModel::toggleLike,
                                onReply = { viewModel.replyTo(comment) },
                                onDelete = { viewModel.delete(it, onCounted) },
                            )
                        }
                    }
                }
            }

            state.replyingTo?.let { parent ->
                Row(
                    Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        "@${parent.author.username} · ${stringResource(R.string.tok_reply)}",
                        style = MaterialTheme.typography.labelMedium,
                        color = TextSecondary,
                        modifier = Modifier.weight(1f),
                    )
                    IconButton(onClick = { viewModel.replyTo(null) }) {
                        Icon(Icons.Filled.Close, stringResource(R.string.cancel), Modifier.size(18.dp))
                    }
                }
            }

            Row(
                Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                OutlinedTextField(
                    value = draft,
                    onValueChange = { draft = it },
                    placeholder = { Text(stringResource(R.string.tok_comment_hint)) },
                    modifier = Modifier.weight(1f),
                    maxLines = 4,
                    shape = RoundedCornerShape(22.dp),
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Send),
                    keyboardActions = KeyboardActions(
                        onSend = {
                            viewModel.send(draft, onCounted)
                            draft = ""
                        }
                    ),
                )

                IconButton(
                    onClick = {
                        viewModel.send(draft, onCounted)
                        draft = ""
                    },
                    enabled = draft.isNotBlank() && !state.sending,
                ) {
                    Icon(Icons.AutoMirrored.Filled.Send, stringResource(R.string.tok_comment_send))
                }
            }
        }
    }
}

@Composable
private fun CommentRow(
    comment: ShortCommentDto,
    replies: List<ShortCommentDto>,
    expanded: Boolean,
    onToggleReplies: () -> Unit,
    onLike: (ShortCommentDto) -> Unit,
    onReply: () -> Unit,
    onDelete: (ShortCommentDto) -> Unit,
) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp)) {
        CommentBody(comment, onLike, onReply, onDelete)

        if (comment.replyCount > 0) {
            Text(
                if (expanded) {
                    stringResource(R.string.tok_replies_hide)
                } else {
                    stringResource(R.string.tok_replies_show, comment.replyCount.toInt())
                },
                style = MaterialTheme.typography.labelMedium,
                color = TextSecondary,
                modifier = Modifier
                    .padding(start = 46.dp, top = 4.dp)
                    .clickable(onClick = onToggleReplies),
            )
        }

        if (expanded) {
            replies.forEach { reply ->
                Box(Modifier.padding(start = 34.dp, top = 8.dp)) {
                    CommentBody(reply, onLike, onReply, onDelete)
                }
            }
        }
    }
}

@Composable
private fun CommentBody(
    comment: ShortCommentDto,
    onLike: (ShortCommentDto) -> Unit,
    onReply: () -> Unit,
    onDelete: (ShortCommentDto) -> Unit,
) {
    Row(verticalAlignment = Alignment.Top) {
        AsyncImage(
            model = comment.author.avatar,
            contentDescription = comment.author.displayName,
            contentScale = ContentScale.Crop,
            modifier = Modifier.size(34.dp).clip(CircleShape).background(TextMuted),
        )

        Spacer(Modifier.width(10.dp))

        Column(Modifier.weight(1f)) {
            Text(
                comment.author.displayName.ifBlank { comment.author.username },
                style = MaterialTheme.typography.labelLarge,
                color = TextSecondary,
            )
            Text(comment.body, style = MaterialTheme.typography.bodyMedium)

            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    stringResource(R.string.tok_reply),
                    style = MaterialTheme.typography.labelMedium,
                    color = TextSecondary,
                    modifier = Modifier.clickable(onClick = onReply),
                )

                if (comment.isMine) {
                    Spacer(Modifier.width(14.dp))
                    Text(
                        stringResource(R.string.delete),
                        style = MaterialTheme.typography.labelMedium,
                        color = StatusError,
                        modifier = Modifier.clickable { onDelete(comment) },
                    )
                }
            }
        }

        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            IconButton(onClick = { onLike(comment) }, modifier = Modifier.size(28.dp)) {
                Icon(
                    if (comment.liked) Icons.Filled.Favorite else Icons.Outlined.FavoriteBorder,
                    stringResource(R.string.tok_like),
                    tint = if (comment.liked) StatusError else TextSecondary,
                    modifier = Modifier.size(18.dp),
                )
            }

            if (comment.likeCount > 0) {
                Text(
                    comment.likeCount.toString(),
                    style = MaterialTheme.typography.labelSmall,
                    color = TextSecondary,
                )
            }
        }
    }
}
