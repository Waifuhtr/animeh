package com.animeh.app.ui.navigation

import androidx.annotation.StringRes
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material.icons.outlined.*
import androidx.compose.ui.graphics.vector.ImageVector
import com.animeh.app.R

/**
 * Every destination, as data.
 *
 * Routes are built through the helpers rather than string-concatenated at call
 * sites, so an argument added to a route is a compile error everywhere it is
 * navigated to instead of a runtime crash on one path nobody tested.
 */
object Routes {
    const val HOME = "home"
    const val DISCOVER = "discover"
    const val LIBRARY = "library"
    const val ROOMS = "rooms"
    const val PROFILE = "profile"

    const val SETTINGS = "settings"
    const val LOGIN = "login"
    const val REGISTER = "register"
    const val FORGOT_PASSWORD = "forgot_password"
    const val CHANGE_PASSWORD = "change_password"

    private const val DETAIL_BASE = "detail"
    const val DETAIL = "$DETAIL_BASE/{workId}"
    fun detail(workId: Long) = "$DETAIL_BASE/$workId"

    // Browsing and filtering live on the Discover tab, which is a top-level
    // destination. There was a `search?query=…&genre=…` route here that no
    // NavHost entry ever registered, so navigating to it threw rather than
    // opening anything.

    // Admin.
    const val ADMIN = "admin"
    const val ADMIN_WORKS = "admin/works"
    const val ADMIN_TENRAI = "admin/tenrai"
    const val ADMIN_USERS = "admin/users"
    const val ADMIN_ANNOUNCEMENTS = "admin/announcements"
    const val ADMIN_LOGS = "admin/logs"
    const val ADMIN_FONTS = "admin/fonts"
    const val ADMIN_TERMS = "admin/terms"
    const val FRIENDS = "friends"
    const val ROOM = "room"

    private const val PROFILE_BASE = "profile/user"
    const val PUBLIC_PROFILE = "$PROFILE_BASE/{userId}"
    fun publicProfile(userId: Long) = "$PROFILE_BASE/$userId"

    const val ADMIN_TMDB = "admin/tmdb"
    const val ADMIN_REPORTS = "admin/reports"
    const val ADMIN_MODERATORS = "admin/moderators"
    const val ADMIN_SERVER = "admin/server"

    private const val ADMIN_WORK_BASE = "admin/work"
    const val ADMIN_WORK_EDIT = "$ADMIN_WORK_BASE/{workId}"
    fun adminWork(workId: Long) = "$ADMIN_WORK_BASE/$workId"

    private const val ADMIN_EPISODES_BASE = "admin/episodes"
    const val ADMIN_EPISODES = "$ADMIN_EPISODES_BASE/{workId}"
    fun adminEpisodes(workId: Long) = "$ADMIN_EPISODES_BASE/$workId"

    private const val ADMIN_EPISODE_BASE = "admin/episode"
    const val ADMIN_EPISODE_EDIT = "$ADMIN_EPISODE_BASE/{workId}/{episodeId}"
    fun adminEpisode(workId: Long, episodeId: Long) = "$ADMIN_EPISODE_BASE/$workId/$episodeId"
}

/**
 * The bottom bar's tabs.
 *
 * Admin is in the list but shown only when the server says the user has the
 * capability — §8's rule, applied to navigation: the tab is drawn from the
 * flag, and every screen behind it is refused server-side regardless.
 */
enum class TopLevelDestination(
    val route: String,
    @StringRes val labelRes: Int,
    val selectedIcon: ImageVector,
    val icon: ImageVector,
    val adminOnly: Boolean = false,
) {
    HOME(Routes.HOME, R.string.nav_home, Icons.Filled.Home, Icons.Outlined.Home),
    DISCOVER(Routes.DISCOVER, R.string.nav_discover, Icons.Filled.Search, Icons.Outlined.Search),
    LIBRARY(Routes.LIBRARY, R.string.nav_library, Icons.Filled.VideoLibrary, Icons.Outlined.VideoLibrary),
    ROOMS(Routes.ROOMS, R.string.nav_rooms, Icons.Filled.Groups, Icons.Outlined.Groups),
    PROFILE(Routes.PROFILE, R.string.nav_profile, Icons.Filled.Person, Icons.Outlined.Person),
    ADMIN(Routes.ADMIN, R.string.nav_admin, Icons.Filled.AdminPanelSettings, Icons.Outlined.AdminPanelSettings, adminOnly = true),
    ;

    companion object {
        fun visible(isAdmin: Boolean): List<TopLevelDestination> =
            entries.filter { !it.adminOnly || isAdmin }
    }
}
