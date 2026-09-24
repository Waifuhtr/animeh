package com.animeh.app.data.local

import com.animeh.app.data.local.dao.SnapshotDao
import com.animeh.app.data.local.entity.SnapshotEntity
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/**
 * A fake table rather than a real Room database — this is the JSON layer,
 * not the SQL, and [SnapshotDao] is a small enough interface that faking it
 * is more honest about what is under test than an in-memory database would
 * be.
 */
class SnapshotCacheTest {

    private val json = Json {
        ignoreUnknownKeys = true
        coerceInputValues = true
    }

    @Serializable
    data class Sample(val name: String = "", val count: Int = 0)

    @Test
    fun `a value written is the value read back`() = runTest {
        val cache = SnapshotCache(FakeDao(), json)

        cache.write("k", Sample("kaori", 7))

        assertEquals(Sample("kaori", 7), cache.read<Sample>("k"))
    }

    @Test
    fun `nothing saved under a key is null, not an exception`() = runTest {
        val cache = SnapshotCache(FakeDao(), json)

        assertNull(cache.read<Sample>("missing"))
    }

    @Test
    fun `a value that will not parse at all is null`() = runTest {
        val dao = FakeDao()
        dao.put(SnapshotEntity("k", "not json at all"))

        assertNull(SnapshotCache(dao, json).read<Sample>("k"))
    }

    @Test
    fun `a shape saved by an older version of the app still reads`() = runTest {
        // What version N wrote before `count` existed. Version N+1's DTO
        // gained the field with a default, and this is the row an app that
        // updated in place actually has on disk.
        val dao = FakeDao()
        dao.put(SnapshotEntity("k", """{"name":"kaori"}"""))

        assertEquals(Sample("kaori", 0), SnapshotCache(dao, json).read<Sample>("k"))
    }

    @Test
    fun `writing again under the same key replaces it`() = runTest {
        val dao = FakeDao()
        val cache = SnapshotCache(dao, json)

        cache.write("k", Sample("first", 1))
        cache.write("k", Sample("second", 2))

        assertEquals(Sample("second", 2), cache.read<Sample>("k"))
        assertEquals(1, dao.rowCount())
    }

    @Test
    fun `two keys do not see each other's value`() = runTest {
        val cache = SnapshotCache(FakeDao(), json)

        cache.write("a", Sample("first"))
        cache.write("b", Sample("second"))

        assertEquals(Sample("first"), cache.read<Sample>("a"))
        assertEquals(Sample("second"), cache.read<Sample>("b"))
    }

    private class FakeDao : SnapshotDao {
        private val rows = mutableMapOf<String, SnapshotEntity>()

        override suspend fun get(key: String): SnapshotEntity? = rows[key]

        override suspend fun put(entity: SnapshotEntity) {
            rows[entity.key] = entity
        }

        override suspend fun clear() {
            rows.clear()
        }

        fun rowCount() = rows.size
    }
}
