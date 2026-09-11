package com.animeh.app.ui.navigation

import androidx.compose.animation.*
import androidx.compose.animation.core.tween
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.animeh.app.data.prefs.AuthState
import com.animeh.app.data.prefs.canManage
import com.animeh.app.data.prefs.isAdmin
import com.animeh.app.data.prefs.isModerator
import com.animeh.app.data.prefs.user
import com.animeh.app.domain.KIND_ANIME
import com.animeh.app.domain.KIND_MANGA
import com.animeh.app.player.ui.PlayerActivity
import com.animeh.app.reader.ReaderScreen
import com.animeh.app.ui.screens.admin.*
import com.animeh.app.ui.screens.auth.*
import com.animeh.app.ui.screens.detail.DetailScreen
import com.animeh.app.ui.screens.discover.DiscoverScreen
import com.animeh.app.ui.screens.home.HomeScreen
import com.animeh.app.ui.screens.leaderboard.LeaderboardScreen
import com.animeh.app.ui.screens.library.LibraryScreen
import com.animeh.app.ui.screens.profile.FrameShopScreen
import com.animeh.app.ui.screens.profile.ProfileScreen
import com.animeh.app.ui.screens.settings.SettingsScreen
import com.animeh.app.ui.screens.admin.AdminShortsScreen
import com.animeh.app.ui.screens.shorts.ShortCreatorScreen
import com.animeh.app.ui.screens.shorts.ShortSearchScreen
import com.animeh.app.ui.screens.shorts.ShortSoundScreen
import com.animeh.app.ui.screens.shorts.ShortTagScreen
import com.animeh.app.ui.screens.shorts.ShortUploadScreen
import com.animeh.app.ui.screens.shorts.ShortsModeSheet
import com.animeh.app.ui.screens.shorts.ShortsScreen
import com.animeh.app.ui.screens.social.*

/**
 * The whole navigation graph.
 *
 * The player is an activity rather than a destination here: it needs its own
 * window flags, orientation handling and immersive mode, and making the rest of
 * the app undo those on every navigation is worse than the one intent.
 */
@Composable
fun AnimehApp(
    authState: AuthState,
    /** A room code from an invite link or a notification, if there is one. */
    roomCode: String? = null,
    onRoomHandled: () -> Unit = {},
    /** An anime a "new episode" notification was tapped for. */
    workId: Long? = null,
    onWorkHandled: () -> Unit = {},
    navController: NavHostController = rememberNavController(),
    joinViewModel: RoomJoinViewModel = hiltViewModel(),
) {
    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination

    val destinations = TopLevelDestination.visible(authState.canManage)
    val showBottomBar = destinations.any { destination ->
        currentRoute?.hierarchy?.any { it.route == destination.route } == true
    }

    // Switching to a tab, rather than stacking another copy of it. Shared with
    // the rails' "see all", so that lands on the tab it belongs to with the
    // bottom bar intact instead of on a dead-end screen.
    // Which shelf "Tümü" was tapped from, read once by Discover when it
    // opens. Not a route argument: Discover is a bottom-bar destination and
    // its route has to stay exactly "discover" for the tab to light up.
    var discoverKind by rememberSaveable { mutableStateOf<String?>(null) }

    // A genre tapped on a work's page. Same mechanism as the shelf above, and
    // for the same reason: Discover's route has to stay "discover".
    var discoverGenre by rememberSaveable { mutableStateOf<String?>(null) }

    // Tapping Home while already on Home offers the short-video mode, which is
    // where she asked for it: AnimehTok is a mode, not a sixth tab, and a tab
    // would put it in front of people who never asked for it.
    var offerShorts by rememberSaveable { mutableStateOf(false) }

    val switchTab: (String) -> Unit = { route ->
        if (route == Routes.HOME && currentRoute?.route == Routes.HOME) {
            offerShorts = true
        }

        navController.navigate(route) {
            popUpTo(navController.graph.findStartDestination().id) {
                saveState = true
            }
            launchSingleTop = true
            restoreState = true
        }
    }

    // An invite link joins the room and then opens it. Joining is what tells
    // the server the room is still alive, so it has to happen before the
    // screen rather than as a side effect of drawing one.
    LaunchedEffect(roomCode) {
        val code = roomCode ?: return@LaunchedEffect

        onRoomHandled()

        if (joinViewModel.join(code)) {
            navController.navigate(Routes.ROOM)
        }
    }

    // A tapped episode notification opens the series it was about.
    LaunchedEffect(workId) {
        val id = workId ?: return@LaunchedEffect

        onWorkHandled()

        navController.navigate(Routes.detail(id))
    }

    if (offerShorts) {
        ShortsModeSheet(
            onDismiss = { offerShorts = false },
            onEnter = {
                offerShorts = false
                navController.navigate(Routes.SHORTS)
            },
        )
    }

    Scaffold(
        bottomBar = {
            if (showBottomBar) {
                NavigationBar {
                    destinations.forEach { destination ->
                        val selected = currentRoute?.hierarchy?.any { it.route == destination.route } == true

                        NavigationBarItem(
                            selected = selected,
                            // Re-selecting a tab returns to its root rather
                            // than stacking another copy.
                            onClick = { switchTab(destination.route) },
                            icon = {
                                Icon(
                                    if (selected) destination.selectedIcon else destination.icon,
                                    contentDescription = stringResource(destination.labelRes),
                                )
                            },
                            label = { Text(stringResource(destination.labelRes)) },
                        )
                    }
                }
            }
        }
    ) { padding ->
        val context = LocalContext.current

        val openPlayer: (Long) -> Unit = { episodeId ->
            context.startActivity(PlayerActivity.intent(context, episodeId))
        }

        // A chapter is read and an episode is played. Which one a row is comes
        // from the row itself — the server sends the work's kind alongside
        // every episode and every history entry, so no screen has to guess.
        val openEpisode: (Long, Boolean) -> Unit = { id, isChapter ->
            if (isChapter) navController.navigate(Routes.reader(id)) else openPlayer(id)
        }

        NavHost(
            navController = navController,
            startDestination = Routes.HOME,
            modifier = Modifier.padding(padding),
            // A short slide, not a default fade: it reads as depth without
            // adding a delay to every navigation.
            enterTransition = { slideInHorizontally(tween(220)) { it / 6 } + fadeIn(tween(220)) },
            exitTransition = { fadeOut(tween(160)) },
            popEnterTransition = { fadeIn(tween(160)) },
            popExitTransition = { slideOutHorizontally(tween(220)) { it / 6 } + fadeOut(tween(220)) },
        ) {
            composable(Routes.HOME) {
                HomeScreen(
                    onWorkClick = { navController.navigate(Routes.detail(it.id)) },
                    onEpisodeClick = openEpisode,
                    onSeeAll = { switchTab(Routes.DISCOVER) },
                    onSeeAllOf = { kind ->
                        discoverKind = kind
                        switchTab(Routes.DISCOVER)
                    },
                )
            }

            composable(Routes.DISCOVER) {
                DiscoverScreen(
                    onWorkClick = { navController.navigate(Routes.detail(it.id)) },
                    startKind = discoverKind,
                    onStartKindHandled = { discoverKind = null },
                    startGenre = discoverGenre,
                    onStartGenreHandled = { discoverGenre = null },
                )
            }

            composable(Routes.LIBRARY) {
                LibraryScreen(
                    signedIn = authState is AuthState.SignedIn,
                    onWorkClick = { navController.navigate(Routes.detail(it.id)) },
                    onEpisodeClick = openEpisode,
                    onSignIn = { navController.navigate(Routes.LOGIN) },
                )
            }

            composable(Routes.ROOMS) {
                RoomsScreen(
                    signedIn = authState is AuthState.SignedIn,
                    onSignIn = { navController.navigate(Routes.LOGIN) },
                    onOpenRoom = { navController.navigate(Routes.ROOM) },
                )
            }

            composable(Routes.PROFILE) {
                ProfileScreen(
                    authState = authState,
                    onSignIn = { navController.navigate(Routes.LOGIN) },
                    onSettings = { navController.navigate(Routes.SETTINGS) },
                    onChangePassword = { navController.navigate(Routes.CHANGE_PASSWORD) },
                    onFriends = { navController.navigate(Routes.FRIENDS) },
                    onPublicProfile = { navController.navigate(Routes.publicProfile(it)) },
                    onFrameShop = { navController.navigate(Routes.FRAME_SHOP) },
                    onLeaderboard = { navController.navigate(Routes.LEADERBOARD) },
                    onShorts = { navController.navigate(Routes.SHORTS) },
                )
            }

            composable(Routes.FRAME_SHOP) {
                FrameShopScreen(
                    // The picture the frames will actually be worn around, so
                    // the preview is of this person rather than of a shape.
                    avatarUrl = authState.user?.avatar.orEmpty(),
                    onBack = { navController.popBackStack() },
                )
            }

            composable(
                route = Routes.READER,
                arguments = listOf(navArgument("chapterId") { type = NavType.LongType }),
            ) { entry ->
                ReaderScreen(
                    chapterId = entry.arguments?.getLong("chapterId") ?: 0L,
                    onBack = { navController.popBackStack() },
                    // Straight to the next chapter rather than back and in
                    // again: the reader is where somebody reads three of them.
                    onChapter = { id ->
                        navController.navigate(Routes.reader(id)) {
                            popUpTo(Routes.READER) { inclusive = true }
                        }
                    },
                )
            }

            composable(Routes.LEADERBOARD) {
                LeaderboardScreen(
                    onBack = { navController.popBackStack() },
                    onProfile = { navController.navigate(Routes.publicProfile(it)) },
                )
            }

            /* ── AnimehTok ─────────────────────────────────────────── */

            // Every one of these opens a video by id by dropping it into the
            // feed, so "open a short" is always the same screen and back
            // always goes where it came from.
            val openShort: (Long) -> Unit = { navController.navigate(Routes.SHORTS) }

            composable(Routes.ADMIN_SHORTS) {
                AdminShortsScreen(onBack = { navController.popBackStack() })
            }

            composable(Routes.SHORTS) {
                ShortsScreen(
                    onBack = { navController.popBackStack() },
                    onOpenTag = { navController.navigate(Routes.shortsTag(it)) },
                    onOpenSound = { navController.navigate(Routes.shortsSound(it)) },
                    onOpenCreator = { navController.navigate(Routes.shortsCreator(it)) },
                    onUpload = { navController.navigate(Routes.SHORTS_UPLOAD) },
                    onSearch = { navController.navigate(Routes.SHORTS_SEARCH) },
                )
            }

            composable(Routes.SHORTS_UPLOAD) {
                ShortUploadScreen(
                    onBack = { navController.popBackStack() },
                    onUploaded = { navController.popBackStack() },
                )
            }

            composable(Routes.SHORTS_SEARCH) {
                ShortSearchScreen(
                    onBack = { navController.popBackStack() },
                    onOpenTag = { navController.navigate(Routes.shortsTag(it)) },
                    onOpenSound = { navController.navigate(Routes.shortsSound(it)) },
                    onOpenCreator = { navController.navigate(Routes.shortsCreator(it)) },
                    onOpenShort = openShort,
                )
            }

            composable(
                route = Routes.SHORTS_TAG,
                arguments = listOf(navArgument("tag") { type = NavType.StringType }),
            ) {
                ShortTagScreen(
                    onBack = { navController.popBackStack() },
                    onOpenShort = openShort,
                )
            }

            composable(
                route = Routes.SHORTS_SOUND,
                arguments = listOf(navArgument("soundId") { type = NavType.StringType }),
            ) {
                ShortSoundScreen(
                    onBack = { navController.popBackStack() },
                    onOpenShort = openShort,
                )
            }

            composable(
                route = Routes.SHORTS_CREATOR,
                arguments = listOf(navArgument("creatorId") { type = NavType.StringType }),
            ) {
                ShortCreatorScreen(
                    onBack = { navController.popBackStack() },
                    onOpenShort = openShort,
                )
            }

            composable(
                route = Routes.DETAIL,
                arguments = listOf(navArgument("workId") { type = NavType.LongType }),
            ) { entry ->
                DetailScreen(
                    workId = entry.arguments?.getLong("workId") ?: 0L,
                    onBack = { navController.popBackStack() },
                    onPlayEpisode = openEpisode,
                    onSignIn = { navController.navigate(Routes.LOGIN) },
                    onOpenRoom = { navController.navigate(Routes.ROOM) },
                    onRecommend = { navController.navigate(Routes.recommend(it)) },
                    onGenreClick = { genre, kind ->
                        discoverGenre = genre
                        discoverKind = kind
                        switchTab(Routes.DISCOVER)
                    },
                    signedIn = authState is AuthState.SignedIn,
                )
            }

            composable(
                route = Routes.RECOMMEND,
                arguments = listOf(navArgument("workId") { type = NavType.LongType }),
            ) {
                RecommendScreen(onBack = { navController.popBackStack() })
            }

            composable(Routes.SETTINGS) {
                SettingsScreen(onBack = { navController.popBackStack() })
            }

            composable(Routes.FRIENDS) {
                FriendsScreen(
                    onBack = { navController.popBackStack() },
                    onOpenProfile = { navController.navigate(Routes.publicProfile(it)) },
                )
            }

            composable(
                route = Routes.PUBLIC_PROFILE,
                arguments = listOf(navArgument("userId") { type = NavType.LongType }),
            ) {
                PublicProfileScreen(
                    onBack = { navController.popBackStack() },
                    onOpenWork = { navController.navigate(Routes.detail(it)) },
                )
            }

            composable(Routes.ROOM) {
                RoomScreen(
                    onBack = { navController.popBackStack() },
                    onPlay = openPlayer,
                )
            }

            composable(Routes.LOGIN) {
                LoginScreen(
                    onSuccess = { navController.popBackStack() },
                    onRegister = { navController.navigate(Routes.REGISTER) },
                    onForgotPassword = { navController.navigate(Routes.FORGOT_PASSWORD) },
                    onBack = { navController.popBackStack() },
                )
            }

            composable(Routes.REGISTER) {
                RegisterScreen(
                    onSuccess = { navController.popBackStack(Routes.HOME, inclusive = false) },
                    onBack = { navController.popBackStack() },
                )
            }

            composable(Routes.FORGOT_PASSWORD) {
                ForgotPasswordScreen(onBack = { navController.popBackStack() })
            }

            composable(Routes.CHANGE_PASSWORD) {
                ChangePasswordScreen(onBack = { navController.popBackStack() })
            }

            adminGraph(navController, authState)
        }
    }
}

/**
 * The admin section.
 *
 * Every screen here also passes through a capability check on the server; this
 * only decides what is drawn.
 */
private fun androidx.navigation.NavGraphBuilder.adminGraph(
    navController: NavHostController,
    authState: AuthState,
) {
    composable(Routes.ADMIN) {
        AdminDashboardScreen(
            isAdmin = authState.isAdmin,
            isModerator = authState.isModerator,
            onSection = { route -> navController.navigate(route) },
        )
    }

    composable(Routes.ADMIN_WORKS) {
        AdminWorksScreen(
            onBack = { navController.popBackStack() },
            onEdit = { navController.navigate(Routes.adminWork(it)) },
            onEpisodes = { navController.navigate(Routes.adminEpisodes(it)) },
            onNew = { navController.navigate(Routes.adminWork(0L)) },
            onImport = { navController.navigate(Routes.ADMIN_TENRAI) },
        )
    }

    // The same screen asked for the other library. A manga's chapters are not
    // episodes — no runtime, no video, and a number that may be 10.5 — so the
    // row leads somewhere else, and "import" means the bridge rather than
    // Tenrai.
    composable(Routes.ADMIN_MANGA_LIBRARY) {
        AdminWorksScreen(
            kind = KIND_MANGA,
            onBack = { navController.popBackStack() },
            onEdit = { navController.navigate(Routes.adminWork(it, KIND_MANGA)) },
            onEpisodes = { navController.navigate(Routes.adminChapters(it)) },
            onNew = { navController.navigate(Routes.adminWork(0L, KIND_MANGA)) },
            onImport = { navController.navigate(Routes.ADMIN_MANGA) },
        )
    }

    composable(
        route = Routes.ADMIN_CHAPTERS,
        arguments = listOf(navArgument("workId") { type = NavType.LongType }),
    ) {
        AdminChaptersScreen(
            onBack = { navController.popBackStack() },
            onPages = { chapterId -> navController.navigate(Routes.adminChapterPages(chapterId)) },
        )
    }

    composable(
        route = Routes.ADMIN_CHAPTER_PAGES,
        arguments = listOf(navArgument("chapterId") { type = NavType.LongType }),
    ) {
        AdminChapterPagesScreen(onBack = { navController.popBackStack() })
    }

    composable(
        route = Routes.ADMIN_WORK_EDIT,
        arguments = listOf(
            navArgument("workId") { type = NavType.LongType },
            navArgument("kind") {
                type = NavType.StringType
                defaultValue = KIND_ANIME
            },
        ),
    ) { entry ->
        AdminWorkEditScreen(
            workId = entry.arguments?.getLong("workId") ?: 0L,
            onBack = { navController.popBackStack() },
        )
    }

    composable(
        route = Routes.ADMIN_EPISODES,
        arguments = listOf(navArgument("workId") { type = NavType.LongType }),
    ) { entry ->
        val workId = entry.arguments?.getLong("workId") ?: 0L
        AdminEpisodesScreen(
            workId = workId,
            onBack = { navController.popBackStack() },
            onEdit = { episodeId -> navController.navigate(Routes.adminEpisode(workId, episodeId)) },
        )
    }

    composable(
        route = Routes.ADMIN_EPISODE_EDIT,
        arguments = listOf(
            navArgument("workId") { type = NavType.LongType },
            navArgument("episodeId") { type = NavType.LongType },
        ),
    ) { entry ->
        AdminEpisodeEditScreen(
            workId = entry.arguments?.getLong("workId") ?: 0L,
            episodeId = entry.arguments?.getLong("episodeId") ?: 0L,
            onBack = { navController.popBackStack() },
        )
    }

    composable(Routes.ADMIN_TENRAI) {
        AdminTenraiScreen(onBack = { navController.popBackStack() })
    }

    composable(Routes.ADMIN_USERS) {
        AdminUsersScreen(
            onBack = { navController.popBackStack() },
            // A moderator reaches this list; only an administrator can mint
            // points, and the server refuses the rest either way.
            canGrantPoints = authState.isAdmin,
        )
    }

    composable(Routes.ADMIN_TMDB) {
        AdminTmdbScreen(onBack = { navController.popBackStack() })
    }

    composable(Routes.ADMIN_REPORTS) {
        AdminReportsScreen(onBack = { navController.popBackStack() })
    }

    composable(Routes.ADMIN_MODERATORS) {
        AdminModeratorsScreen(onBack = { navController.popBackStack() })
    }

    composable(Routes.ADMIN_SERVER) {
        AdminServerScreen(onBack = { navController.popBackStack() })
    }

    composable(Routes.ADMIN_ANNOUNCEMENTS) {
        AdminAnnouncementsScreen(onBack = { navController.popBackStack() })
    }

    composable(Routes.ADMIN_LOGS) {
        AdminLogsScreen(onBack = { navController.popBackStack() })
    }

    composable(Routes.ADMIN_FONTS) {
        AdminFontsScreen(onBack = { navController.popBackStack() })
    }

    composable(Routes.ADMIN_TERMS) {
        AdminTermsScreen(onBack = { navController.popBackStack() })
    }

    composable(Routes.ADMIN_FRAMES) {
        AdminFramesScreen(onBack = { navController.popBackStack() })
    }

    composable(Routes.ADMIN_MANGA) {
        AdminMangaScreen(onBack = { navController.popBackStack() })
    }
}
