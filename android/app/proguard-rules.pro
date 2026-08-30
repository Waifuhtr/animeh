# kotlinx.serialization generates a companion `serializer()` per @Serializable
# class and looks it up reflectively; R8 cannot see that use and strips it,
# which shows up at runtime as "Serializer for class X not found".
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**

-keepclassmembers class kotlinx.serialization.json.** {
    *** Companion;
}
-keepclasseswithmembers class kotlinx.serialization.json.** {
    kotlinx.serialization.KSerializer serializer(...);
}

-keep,includedescriptorclasses class com.animeh.app.**$$serializer { *; }
-keepclassmembers class com.animeh.app.** {
    *** Companion;
}
-keepclasseswithmembers class com.animeh.app.** {
    kotlinx.serialization.KSerializer serializer(...);
}

# Retrofit keeps generic signatures on service interfaces to build calls.
-keepattributes Signature, Exceptions
-keep,allowobfuscation,allowshrinking interface retrofit2.Call
-keep,allowobfuscation,allowshrinking class retrofit2.Response
-keep,allowobfuscation,allowshrinking class kotlin.coroutines.Continuation

# OkHttp references optional platform classes that are absent on Android.
-dontwarn okhttp3.internal.platform.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# Media3 loads renderers and extractors by name.
-keep class androidx.media3.** { *; }
-dontwarn androidx.media3.**
