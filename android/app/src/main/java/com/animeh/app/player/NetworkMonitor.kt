package com.animeh.app.player

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import androidx.core.content.getSystemService
import kotlinx.coroutines.channels.awaitClose
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.callbackFlow
import kotlinx.coroutines.flow.distinctUntilChanged
import javax.inject.Inject
import javax.inject.Singleton

/**
 * What kind of connection is available, and when it changes.
 *
 * §11 asks for Wi-Fi/mobile detection and for recovery when the network
 * changes. The classification is deliberately coarse — three buckets — because
 * the decisions it feeds are coarse: which rendition to start with, and how
 * much to buffer. A finer estimate would be false precision.
 */
@Singleton
class NetworkMonitor @Inject constructor(
    private val context: Context,
) {

    private val connectivity: ConnectivityManager?
        get() = context.getSystemService()

    /** The current class, read synchronously for a start-up decision. */
    fun current(): ConnectionClass {
        val manager = connectivity ?: return ConnectionClass.UNKNOWN
        val network = manager.activeNetwork ?: return ConnectionClass.UNKNOWN
        val capabilities = manager.getNetworkCapabilities(network) ?: return ConnectionClass.UNKNOWN

        return classify(capabilities)
    }

    fun isOnline(): Boolean {
        val manager = connectivity ?: return false
        val network = manager.activeNetwork ?: return false
        val capabilities = manager.getNetworkCapabilities(network) ?: return false

        // VALIDATED, not just CONNECTED: a captive portal reports a connection
        // that cannot reach anything.
        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
            capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
    }

    /** Changes, so the player can re-evaluate quality after a handover. */
    val connectionClass: Flow<ConnectionClass> = callbackFlow {
        val manager = connectivity
        if (manager == null) {
            trySend(ConnectionClass.UNKNOWN)
            awaitClose { }
            return@callbackFlow
        }

        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                trySend(current())
            }

            override fun onLost(network: Network) {
                trySend(ConnectionClass.UNKNOWN)
            }

            override fun onCapabilitiesChanged(network: Network, capabilities: NetworkCapabilities) {
                // Fires on a wifi-to-cellular handover, which is exactly the
                // moment the buffer profile is wrong.
                trySend(classify(capabilities))
            }
        }

        trySend(current())
        manager.registerDefaultNetworkCallback(callback)

        awaitClose { manager.unregisterNetworkCallback(callback) }
    }.distinctUntilChanged()

    private fun classify(capabilities: NetworkCapabilities): ConnectionClass = when {
        capabilities.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> ConnectionClass.WIFI
        capabilities.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> ConnectionClass.WIFI
        capabilities.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> {
            // The platform's own estimate. It is optimistic, which is why the
            // threshold for "fast" is well above what 720p actually needs.
            val downstream = capabilities.linkDownstreamBandwidthKbps
            if (downstream >= FAST_CELLULAR_KBPS) ConnectionClass.CELLULAR_FAST
            else ConnectionClass.CELLULAR_SLOW
        }
        else -> ConnectionClass.UNKNOWN
    }

    private companion object {
        const val FAST_CELLULAR_KBPS = 4_000
    }
}
