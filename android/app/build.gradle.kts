import java.net.URI
import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.ksp)
    alias(libs.plugins.hilt)
}

// The backend address is configuration, not source. `local.properties` is
// git-ignored, so a developer's own WordPress URL never lands in a commit, and
// the value can be overridden per build without editing this file.
val localProperties = Properties().apply {
    val file = rootProject.file("local.properties")
    if (file.exists()) file.inputStream().use { load(it) }
}

fun setting(key: String, fallback: String): String =
    (localProperties.getProperty(key) ?: System.getenv(key) ?: fallback)

android {
    namespace = "com.animeh.app"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.animeh.app"
        minSdk = 24
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        vectorDrawables.useSupportLibrary = true

        // Shipped as a default the user can change in Settings, not as a
        // hard-coded endpoint: §5.3 of the migration design has the backend
        // moving hosts, and the app has to follow.
        buildConfigField(
            "String",
            "DEFAULT_API_BASE",
            "\"${setting("ANIMEH_API_BASE", "https://oyunceviri.riaslink.fun/wp-json/animeh/v1/")}\"",
        )

        // The site whose /oda/ links this build claims as its own.
        //
        // An App Link has to name a host at build time — there is no way to
        // declare "whatever server this install is pointed at" — so it is
        // derived from the API address, which is the same site in every
        // ordinary setup. ANIMEH_LINK_HOST overrides it for an install whose
        // links live somewhere else.
        //
        // Claiming it is only half of the handshake: Android verifies the
        // claim against /.well-known/assetlinks.json, which the plugin serves
        // once an operator has pasted this build's signing fingerprint. Until
        // then the link opens the web page, and its button opens the app.
        //
        // `URI` is imported rather than written as `java.net.URI`: inside a
        // Kotlin build script `java` already means Gradle's own java
        // extension, so the fully qualified name resolves to the wrong thing
        // and the script does not compile.
        manifestPlaceholders["roomLinkHost"] = setting(
            "ANIMEH_LINK_HOST",
            runCatching {
                URI(setting("ANIMEH_API_BASE", "https://oyunceviri.riaslink.fun/wp-json/animeh/v1/")).host
            }.getOrNull() ?: "oyunceviri.riaslink.fun",
        )
    }

    signingConfigs {
        // Only configured when a keystore is actually present, so a debug
        // build on a fresh clone does not fail asking for one.
        val storeFilePath = setting("ANIMEH_KEYSTORE", "")
        if (storeFilePath.isNotBlank() && file(storeFilePath).exists()) {
            create("release") {
                storeFile = file(storeFilePath)
                storePassword = setting("ANIMEH_KEYSTORE_PASSWORD", "")
                keyAlias = setting("ANIMEH_KEY_ALIAS", "")
                keyPassword = setting("ANIMEH_KEY_PASSWORD", "")
            }
        }
    }

    buildTypes {
        debug {
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
            isMinifyEnabled = false
        }
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfig = signingConfigs.findByName("release")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        // java.time on API 24: the player's duration formatting uses it, and
        // desugaring is cheaper than a parallel implementation.
        isCoreLibraryDesugaringEnabled = true
    }

    kotlinOptions {
        jvmTarget = "17"
        freeCompilerArgs += listOf(
            "-opt-in=kotlin.RequiresOptIn",
            "-opt-in=androidx.compose.material3.ExperimentalMaterial3Api",
            "-opt-in=androidx.compose.foundation.ExperimentalFoundationApi",
        )
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }

    testOptions {
        unitTests {
            isIncludeAndroidResources = true
            isReturnDefaultValues = true
        }
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)

    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.graphics)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.material.icons)
    implementation(libs.androidx.navigation.compose)

    implementation(libs.hilt.android)
    ksp(libs.hilt.compiler)
    implementation(libs.androidx.hilt.navigation.compose)

    implementation(libs.retrofit)
    implementation(libs.retrofit.serialization)
    implementation(libs.okhttp)
    implementation(libs.okhttp.logging)
    implementation(libs.kotlinx.serialization.json)

    implementation(libs.androidx.room.runtime)
    implementation(libs.androidx.room.ktx)
    ksp(libs.androidx.room.compiler)

    implementation(libs.androidx.datastore.preferences)

    // No google-services plugin and no google-services.json. Firebase is
    // configured at runtime from what the server reports, for the same reason
    // the server address is: pointing the app at a different project should
    // not need a new build, and an APK that refuses to compile without a file
    // only the site owner has is an APK nobody else can build.
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.database)
    implementation(libs.firebase.messaging)
    implementation(libs.androidx.security.crypto)
    implementation(libs.androidx.paging.runtime)
    implementation(libs.androidx.paging.compose)
    implementation(libs.androidx.work.runtime)

    implementation(libs.coil.compose)

    implementation(libs.media3.exoplayer)
    implementation(libs.media3.exoplayer.hls)
    implementation(libs.media3.datasource.okhttp)
    implementation(libs.media3.ui)
    implementation(libs.media3.session)
    implementation(libs.media3.common)

    coreLibraryDesugaring(libs.desugar.jdk.libs)

    debugImplementation(libs.androidx.compose.ui.tooling)
    debugImplementation(libs.androidx.compose.ui.test.manifest)

    testImplementation(libs.junit)
    testImplementation(libs.kotlinx.coroutines.test)
    testImplementation(libs.turbine)
    testImplementation(libs.mockwebserver)

    androidTestImplementation(libs.androidx.junit)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(platform(libs.androidx.compose.bom))
    androidTestImplementation(libs.androidx.compose.ui.test.junit4)
}
