/**
 * Cross-checks the plugin's SigV4 signer against an independent implementation.
 *
 * Backblaze cannot be reached from this environment, so no request can be sent
 * to prove a signature is accepted. What can be proven is that the signature
 * matches what a separate, widely used implementation produces for the same
 * request — and that both reproduce the signature published in the AWS
 * documentation, which anchors the pair to something outside this project.
 *
 * Usage: node wordpress-plugin/animeh/tests/sigv4-crosscheck.mjs
 */
import { execFileSync } from 'node:child_process'
import { createRequire } from 'node:module'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const REPO = resolve(HERE, '..', '..', '..')
const aws4 = createRequire(join(REPO, 'player', 'package.json'))('aws4')

const CREDENTIALS = {
  accessKeyId: 'AKIDEXAMPLE',
  secretAccessKey: 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
}
/** Matches the timestamp the PHP side signs with. */
const AMZ_DATE = '20150830T123600Z'

/** The signature published in the AWS SigV4 documentation for `get-vanilla`. */
const DOCUMENTED_SIGNATURE = '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31'

let checks = 0
let failures = 0

function check(name, condition, detail = '') {
  checks++
  if (condition) {
    console.log(`  ok   ${name}${detail ? ` — ${detail}` : ''}`)
  } else {
    failures++
    console.log(`  FAIL ${name}${detail ? `\n       ${detail}` : ''}`)
  }
}

/** Sign the same request with aws4, for comparison. */
function signWithAws4(entry) {
  const url = new URL(entry.url)
  const service = entry.service ?? 's3'

  const headers = { 'X-Amz-Date': AMZ_DATE }
  for (const [name, value] of Object.entries(entry.headers ?? {})) {
    headers[name] = value
  }
  // S3 signs the payload hash; other services in this suite do not.
  if (service === 's3') {
    headers['X-Amz-Content-Sha256'] =
      'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
  }

  const options = {
    host: url.host,
    // aws4 takes the path with its query string attached.
    path: url.pathname + url.search,
    method: entry.method,
    service,
    region: entry.region,
    headers,
    body: '',
    doNotModifyHeaders: false,
  }
  aws4.sign(options, CREDENTIALS)
  return options.headers.Authorization
}

const raw = execFileSync('php', [join(HERE, 'sigv4-cases.php')], { encoding: 'utf8' })
const entries = JSON.parse(raw)

console.log(`\ncross-checking ${entries.length} signed requests against aws4\n`)

for (const entry of entries) {
  const expected = signWithAws4(entry)
  const actual = entry.authorization
  check(
    entry.name,
    expected === actual,
    expected === actual ? '' : `aws4: ${expected}\n       ours: ${actual}`,
  )
}

const documented = entries.find((entry) => entry.name === 'aws documented vector')
check(
  'matches the signature published by AWS',
  documented?.authorization?.endsWith(DOCUMENTED_SIGNATURE) === true,
  documented?.authorization ?? 'case missing',
)

// A presigned URL has to be a usable URL, whatever else is true of it.
for (const entry of entries.filter((e) => e.presigned)) {
  let parsed
  try {
    parsed = new URL(entry.presigned)
  } catch {
    parsed = null
  }
  const params = parsed?.searchParams
  const wellFormed =
    parsed !== null &&
    params.get('X-Amz-Algorithm') === 'AWS4-HMAC-SHA256' &&
    /^[0-9a-f]{64}$/.test(params.get('X-Amz-Signature') ?? '') &&
    params.get('X-Amz-Expires') === '3600' &&
    params.get('X-Amz-SignedHeaders') === 'host'
  check(`presigned URL is well formed — ${entry.name}`, wellFormed, entry.presigned?.slice(0, 110))
}

console.log(`\n${checks - failures}/${checks} checks passed`)
process.exit(failures === 0 ? 0 : 1)
