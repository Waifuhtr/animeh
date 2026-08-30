<?php
/**
 * Emits signed requests as JSON, for cross-checking against an independent
 * implementation. See `tests/sigv4-crosscheck.mjs`.
 *
 * @package Animeh
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../src/Support/S3Signer.php';

use Animeh\Support\S3Signer;

const KEY_ID  = 'AKIDEXAMPLE';
const SECRET  = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
const STAMP   = 1440938160; // 2015-08-30T12:36:00Z, the date in the AWS example.

/**
 * The request shapes this plugin actually issues, plus the encoding corners
 * that break naive implementations: spaces and non-ASCII in keys, query
 * parameters that must be sorted and escaped, odd header whitespace, and a
 * non-default port.
 *
 * @return array<int, array<string, mixed>>
 */
function sigv4_cases(): array {
	return array(
		array(
			'name'    => 'list bucket',
			'method'  => 'GET',
			'url'     => 'https://s3.us-west-004.backblazeb2.com/animeh-media?list-type=2&max-keys=100',
			'headers' => array(),
			'region'  => 'us-west-004',
		),
		array(
			'name'    => 'head object',
			'method'  => 'HEAD',
			'url'     => 'https://s3.us-west-004.backblazeb2.com/animeh-media/anime/one-piece/season-01/episode-001/master.m3u8',
			'headers' => array(),
			'region'  => 'us-west-004',
		),
		array(
			'name'    => 'put with content type',
			'method'  => 'PUT',
			'url'     => 'https://s3.eu-central-003.backblazeb2.com/animeh-media/anime/x/subtitles/tr.ass',
			'headers' => array( 'content-type' => 'text/plain; charset=utf-8' ),
			'region'  => 'eu-central-003',
		),
		array(
			// A Turkish title survives slugging, but the corner is worth pinning.
			// Built through encode_key, which is how every real call builds a URL.
			'name'    => 'key with spaces and non-ascii',
			'method'  => 'GET',
			'url'     => 'https://s3.us-west-004.backblazeb2.com' . S3Signer::encode_key( 'animeh-media/anime/kimetsu no yaiba/bölüm 1/güneş.mp4' ),
			'headers' => array(),
			'region'  => 'us-west-004',
		),
		array(
			// `+`, `&` and `=` are legal in an object key and all encode
			// differently from a naive URL escape.
			'name'    => 'key with reserved characters',
			'method'  => 'GET',
			'url'     => 'https://s3.us-west-004.backblazeb2.com' . S3Signer::encode_key( 'animeh-media/a+b/c&d/e=f.ts' ),
			'headers' => array(),
			'region'  => 'us-west-004',
		),
		array(
			'name'    => 'query needing sort and escape',
			'method'  => 'GET',
			'url'     => 'https://s3.us-west-004.backblazeb2.com/animeh-media?prefix=anime/one piece/&delimiter=/&list-type=2&continuation-token=a%2Bb%3Dc',
			'headers' => array(),
			'region'  => 'us-west-004',
		),
		array(
			'name'    => 'multipart part upload',
			'method'  => 'PUT',
			'url'     => 'https://s3.us-west-004.backblazeb2.com/animeh-media/anime/x/e1/video.mp4?partNumber=7&uploadId=abc123',
			'headers' => array( 'content-length' => '5242880' ),
			'region'  => 'us-west-004',
		),
		array(
			'name'    => 'headers with awkward whitespace',
			'method'  => 'PUT',
			'url'     => 'https://s3.us-west-004.backblazeb2.com/animeh-media/x.bin',
			'headers' => array(
				'content-type'         => '  application/octet-stream  ',
				'x-amz-meta-anime'     => "one   piece",
				'x-amz-meta-uploader'  => 'animeh',
			),
			'region'  => 'us-west-004',
		),
		array(
			'name'    => 'root path',
			'method'  => 'GET',
			'url'     => 'https://s3.us-west-004.backblazeb2.com/',
			'headers' => array(),
			'region'  => 'us-west-004',
		),
		array(
			'name'    => 'non-default port',
			'method'  => 'GET',
			'url'     => 'https://storage.example.com:9000/animeh-media/x.mp4',
			'headers' => array(),
			'region'  => 'us-west-004',
		),
	);
}

$out = array();
foreach ( sigv4_cases() as $case ) {
	$signer  = new S3Signer( KEY_ID, SECRET, (string) $case['region'], 's3' );
	$headers = $signer->sign_request(
		(string) $case['method'],
		(string) $case['url'],
		(array) $case['headers'],
		S3Signer::EMPTY_PAYLOAD_HASH,
		STAMP
	);

	$out[] = array(
		'name'          => $case['name'],
		'method'        => $case['method'],
		'url'           => $case['url'],
		'region'        => $case['region'],
		'headers'       => $case['headers'],
		'authorization' => $headers['Authorization'],
		'presigned'     => $signer->presign_url(
			'GET',
			(string) $case['url'],
			3600,
			array(),
			STAMP
		),
	);
}

// The signature published in the AWS SigV4 documentation, which pins the
// implementation to something outside this project entirely.
$documented = new S3Signer( KEY_ID, SECRET, 'us-east-1', 'service' );
$out[]      = array(
	'name'          => 'aws documented vector',
	'method'        => 'GET',
	'url'           => 'https://example.amazonaws.com/',
	'region'        => 'us-east-1',
	'service'       => 'service',
	'headers'       => array(),
	'authorization' => $documented->sign_request( 'GET', 'https://example.amazonaws.com/', array(), S3Signer::EMPTY_PAYLOAD_HASH, STAMP )['Authorization'],
);

echo json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ), "\n";
