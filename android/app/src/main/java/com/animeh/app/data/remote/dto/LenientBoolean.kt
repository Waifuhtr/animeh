package com.animeh.app.data.remote.dto

import kotlinx.serialization.KSerializer
import kotlinx.serialization.descriptors.PrimitiveKind
import kotlinx.serialization.descriptors.PrimitiveSerialDescriptor
import kotlinx.serialization.descriptors.SerialDescriptor
import kotlinx.serialization.encoding.Decoder
import kotlinx.serialization.encoding.Encoder
import kotlinx.serialization.json.JsonDecoder
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull

/**
 * A boolean as the database hands it over.
 *
 * `$wpdb` returns every column as a string, so a `tinyint(1)` arrives as
 * `"1"` rather than `true` unless the endpoint casts it on the way out. The
 * lenient parser already accepts quoted numbers, which is why `"size_bytes":
 * "0"` went unnoticed; booleans were the gap, and a single uncast column was
 * enough to fail the whole response and leave the screen with nothing.
 *
 * The server is the right place to fix a wrong type and it now casts these,
 * but a client that collapses over one bad column is too brittle for an API
 * whose rows come out of MySQL as text. Accepting the string forms here keeps
 * a screen working when the next column is missed.
 *
 * Writing is unchanged: a real boolean goes out.
 */
object LenientBoolean : KSerializer<Boolean> {

    override val descriptor: SerialDescriptor =
        PrimitiveSerialDescriptor("com.animeh.LenientBoolean", PrimitiveKind.BOOLEAN)

    override fun serialize(encoder: Encoder, value: Boolean) = encoder.encodeBoolean(value)

    override fun deserialize(decoder: Decoder): Boolean {
        // Only a JSON body can be inspected this way; anything else takes the
        // ordinary path rather than being second-guessed.
        val json = decoder as? JsonDecoder ?: return decoder.decodeBoolean()

        val primitive = json.decodeJsonElement() as? JsonPrimitive ?: return false

        primitive.booleanOrNull?.let { return it }

        return when (primitive.content.trim().lowercase()) {
            "1", "true", "yes", "on" -> true
            else -> false
        }
    }
}
