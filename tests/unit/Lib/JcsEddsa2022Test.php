<?php

namespace Zotlabs\Tests\Unit\Lib;

use Zotlabs\Lib\JcsEddsa2022;
use Zotlabs\Tests\Unit\UnitTestCase;

class JcsEddsa2022Test extends UnitTestCase {

	public function testVerifyFromSpec() {
		$publicKey = 'z6MkrJVnaZkeFzdQyMZu1cgjg7k1pZZ6pvBQ7XJPt4swbTQ2';
		$privateKey = 'z3u2en7t5LR2WtQH5PfFqMqwVHBeXouLzo6haApm8XHqvjxq';

		$document = '{
			"@context": [
				"https://www.w3.org/ns/credentials/v2",
				"https://www.w3.org/ns/credentials/examples/v2"
			],
			"id": "urn:uuid:58172aac-d8ba-11ed-83dd-0b3aef56cc33",
			"type": [
				"VerifiableCredential",
				"AlumniCredential"
			],
			"name": "Alumni Credential",
			"description": "A minimum viable example of an Alumni Credential.",
			"issuer": "https://vc.example/issuers/5678",
			"validFrom": "2023-01-01T00:00:00Z",
			"credentialSubject": {
			"id": "did:example:abcdefgh",
			"alumniOf": "The School of Examples"
			},
			"proof": {
				"type": "DataIntegrityProof",
				"cryptosuite": "eddsa-jcs-2022",
				"created": "2023-02-24T23:36:38Z",
				"verificationMethod": "https://vc.example/issuers/5678#z6MkrJVnaZkeFzdQyMZu1cgjg7k1pZZ6pvBQ7XJPt4swbTQ2",
				"proofPurpose": "assertionMethod",
				"proofValue": "z3P6rHMUaWG6e3Ac6xYFht8aEvoVXndgKTtEY8kzWYXzk8dKmAo2GJeZiJw4qoZ2PGp4ugdaHx3oQiLpeFBLDqP2M"
			}
		}';

	$verified = (new JcsEddsa2022())->verify(json_decode($document, true), $publicKey);
	$this->assertTrue($verified, 'Verify eddsa-jcs-2022 (from specification)');

	}

	public function testSignAndVerify() {
		$publicKey = 'z6MkfpucGTDbMZADwM6vEa8pS3s8Z9xqSEn6HihijZ4fVs9d';
		$channel = [
			'channel_url' => 'https://example.com/channel/klingon',
			'channel_epubkey' => 'FGdbYgr526Swuyya3e8epCBdHahlWNg9I0sBhMKCzpw',
			'channel_eprvkey' => 'StLRo8xb7VJ5XdR10OUYQM/uooP7D7fMlgvQFa1wrZIUZ1tiCvnbpLC7LJrd7x6kIF0dqGVY2D0jSwGEwoLOnA',
			'channel_address' => 'klingon@example.com',
			'channel_system' => false,
		];

		$document = '{
			"@context": [
				"https://www.w3.org/ns/activitystreams",
				"https://w3id.org/security/v1",
				"https://www.w3.org/ns/did/v1",
				"https://w3id.org/security/multikey/v1",
				{
					"nomad": "https://example.com/apschema#",
					"toot": "http://joinmastodon.org/ns#",
					"litepub": "http://litepub.social/ns#",
					"manuallyApprovesFollowers": "as:manuallyApprovesFollowers",
					"oauthRegistrationEndpoint": "litepub:oauthRegistrationEndpoint",
					"sensitive": "as:sensitive",
					"movedTo": "as:movedTo",
					"discoverable": "toot:discoverable",
					"indexable": "toot:indexable",
					"capabilities": "litepub:capabilities",
					"acceptsJoins": "litepub:acceptsJoins",
					"Hashtag": "as:Hashtag",
					"canReply": "toot:canReply",
					"canSearch": "nomad:canSearch",
					"approval": "toot:approval",
					"expires": "nomad:expires",
					"directMessage": "nomad:directMessage",
					"Category": "nomad:Category",
					"copiedTo": "nomad:copiedTo",
					"searchContent": "nomad:searchContent",
					"searchTags": "nomad:searchTags"
				}
			],
			"type": "Person",
			"id": "https://example.com/channel/klingon",
			"preferredUsername": "klingon",
			"name": "klingon",
			"created": "2023-07-13T20:23:32Z",
			"updated": "2023-07-13T20:23:32Z",
			"icon": {
				"type": "Image",
				"mediaType": "image/png",
				"updated": "2023-07-13T20:23:32Z",
				"url": "https://example.com/photo/profile/l/2",
				"height": 300,
				"width": 300
			},
			"url": "https://example.com/channel/klingon",
			"tag": [
				{
					"type": "Note",
					"name": "Protocol",
					"content": "zot6"
				},
				{
					"type": "Note",
					"name": "Protocol",
					"content": "nomad"
				},
				{
					"type": "Note",
					"name": "Protocol",
					"content": "activitypub"
				}
			],
			"inbox": "https://example.com/inbox/klingon",
			"outbox": "https://example.com/outbox/klingon",
			"followers": "https://example.com/followers/klingon",
			"following": "https://example.com/following/klingon",
			"wall": "https://example.com/outbox/klingon",
			"endpoints": {
				"sharedInbox": "https://example.com/inbox",
				"oauthRegistrationEndpoint": "https://example.com/api/client/register",
				"oauthAuthorizationEndpoint": "https://example.com/authorize",
				"oauthTokenEndpoint": "https://example.com/token",
				"searchContent": "https://example.com/search/klingon?search={}",
				"searchTags": "https://example.com/search/klingon?tag={}"
			},
			"discoverable": true,
			"canSearch": [],
			"indexable": false,
			"publicKey": {
				"id": "https://example.com/channel/klingon?operation=rsakey",
				"owner": "https://example.com/channel/klingon",
				"signatureAlgorithm": "http://www.w3.org/2001/04/xmldsig-more#rsa-sha256",
				"publicKeyPem": "-----BEGIN PUBLIC KEY-----
		MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA+LXyOD/bzzVgM/nUOJ5m
		c4WrQPMlhKqWJvKrumdQw9JJYcyaZp/jmMxDx/w/EwVw+wnV5wZcD0yBVhC7NPRa
		nYc5OfNhS4MO74xgZrj+VWSTzNo3YooS/dEIIvsu6bhxfooHj17SA6pMRnZkkVpk
		ykpPRYwJw+NvKcRwzpF06rxMqjZ+Bp0ea/X37j4cHaosRoQTJiHmMKKnpByKdImF
		TR1juJ69ASh6nh8YVGcz6fz1jBQZPMx05tfNdyN5oZRTr8Nug2CiF3V7yKKS14HD
		kE9eeFeTMt58Qi+8kprATYxKrlIuTZmI4YdIRgtM+tPQsosKTFmjzbef4dYooutv
		T7XfrE+wYVZlx2pkaeFiKrJVacpmmFJe8zCIFXrofq1aOagU1kpwnXgjneCttA+M
		OJ3Y+cPamdfRQDtsBcokJUD40RTwux6OGW9zqkJIpniVB+CZu4nTOHCzMJwbxF0p
		JmGZd9kc3PR6Uf/IHAb1xeyTi4FyyYTbRDYuJyqRKbe880QUwgCBcogIbNy4xxsH
		UTMy0ucWaDSBRahKUIHl3FRglvnI754NJSXBDIQOwC9oRRH27Vmm1Jy8sltmFLFr
		ENJCGgOH8Bhpk+y1jtw1jpTig76wIvw+6zQtgNSfPnrNGIHt5mcoy4pFFXLv2lK2
		/u26hUGQAq71Ra0DwgXIWFECAwEAAQ==
		-----END PUBLIC KEY-----
		"
			},
			"assertionMethod": [
				{
					"id": "https://example.com/channel/klingon#z6MkfpucGTDbMZADwM6vEa8pS3s8Z9xqSEn6HihijZ4fVs9d",
					"type": "Multikey",
					"controller": "https://example.com/channel/klingon",
					"publicKeyMultibase": "z6MkfpucGTDbMZADwM6vEa8pS3s8Z9xqSEn6HihijZ4fVs9d"
				}
			],
			"manuallyApprovesFollowers": true
		}';

		$algorithm = new JcsEddsa2022();
		$documentArray = json_decode($document,true);
		$documentArray['proof'] = $algorithm->sign($documentArray, $channel);

		$verified = (new JcsEddsa2022())->verify($documentArray, $publicKey);
		$this->assertTrue($verified, 'Verify encode and decode eddsa-jcs-2022');

	}
}
