package com.animeh.app.ui.navigation

import androidx.compose.animation.*
import androidx.compose.animation.core.tween
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
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
import com.animeh.app.data.prefs.isAdmin
import com.animeh.app.player.ui.PlayerActivity
import com.animeh.app.ui.screens.admin.*
import com.animeh.app.ui.screens.auth.*
import com.animeh.app.ui.screens.detail.DetailScreen
import com.animeh.app.ui.screens.discover.DiscoverScreen
import com.animeh.app.ui.screens.home.HomeScreen
import com.animeh.app.ui.screens.library.LibraryScreen
import com.animeh.app.ui.screens.profile.ProfileScreen
import com.animeh.app.ui.screens.settings.SettingsScreen

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
    navController: NavHostController = rememberNavController(),
) {
    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination

    val destinations = TopLevelDestination.visible(authState.isAdmin)
    val showBottomBar = destinations.any { destination ->
        currentRoute?.hierarchy?.any { it.route == destination.route } == true
    }

    // Switching to a tab, rather than stacking another copy of it. Shared with
    // the rails' "see all", so that lands on the tab it belongs to with the
    // bottom bar intact instead of on a dead-end screen.
    val switchTab: (String) -> Unit = { route ->
        navController.navigate(route) {
            popUpTo(navController.graph.findStartDestination().id) {
                saveState = true
            }
            launchSingleTop = true
            restoreState = true
        }
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
                    onEpisodeClick = openPlayer,
                    onSeeAll = { switchTab(Routes.DISCOVER) },
                )
            }

            composable(Routes.DISCOVER) {
                DiscoverScreen(
                    onWorkClick = { navController.navigate(Routes.detail(it.id)) },
                )
            }

            composable(Routes.LIBRARY) {
                LibraryScreen(
                    signedIn = authState is AuthState.SignedIn,
                    onWorkClick = { navController.navigate(Routes.detail(it.id)) },
                    onEpisodeClick = openPlayer,
                    onSignIn = { navController.navigate(Routes.LOGIN) },
                )
            }

            composable(Routes.PROFILE) {
                ProfileScreen(
                    authState = authState,
                    onSignIn = { navController.navigate(Routes.LOGIN) },
                    onSettings = { navController.navigate(Routes.SETTINGS) },
                    onChangePassword = { navController.navigate(Routes.CHANGE_PASSWORD) },
                )
            }

            composable(
                route = Routes.DETAIL,
                arguments = listOf(navArgument("workId") { type = NavType.LongType }),
            ) { entry ->
                DetailScreen(
                    workId = entry.arguments?.getLong("workId") ?: 0L,
                    onBack = { navController.popBackStack() },
                    onPlayEpisode = openPlayer,
                    onSignIn = { navController.navigate(Routes.LOGIN) },
                    signedIn = authState is AuthState.SignedIn,
                )
            }

            composable(Routes.SETTINGS) {
                SettingsScreen(onBack = { navController.popBackStack() })
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

    composable(
        route = Routes.ADMIN_WORK_EDIT,
        arguments = listOf(navArgument("workId") { type = NavType.LongType }),
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
        AdminUsersScreen(onBack = { navController.popBackStack() })
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
}
