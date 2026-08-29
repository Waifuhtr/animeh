/**
 * Entry point for the WordPress plugin bundle.
 *
 * Pulls in the stylesheets so the build emits a single CSS file the plugin can
 * enqueue, and re-exports the surface the admin panel uses. Kept separate from
 * `src/index.ts` because that one is the library API and should not force a
 * CSS import on consumers that supply their own skin.
 */
import './ui/player.css'
import './panel/panel.css'

export * from './index.ts'
