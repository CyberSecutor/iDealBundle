<?php

namespace Wrep\IDealBundle\IDeal;

use Wrep\IDealBundle\Exception\IDealException;

class Response
{
	const TYPE_DIRECTORY = 'DirectoryRes';
	const TYPE_TRANSACTION = 'AcquirerTrxRes';
	const TYPE_STATUS = 'AcquirerStatusRes';
	const TYPE_ERROR = 'AcquirerErrorRes';

	private $xml;

	/**
	 * Construct an Response
	 *
	 * @param string Response XML from the acquirer
	 *
	 * @throws IDealException if the signature is invalid or we can't verify it
	 */
	public function __construct($responseXml, $acquirerCertificate)
	{
		try {
			$this->xml = new \SimpleXMLElement($responseXml);
		} catch (\Exception $e) {
			throw new \RuntimeException('Invalid response XML: ' . $e->getMessage(), 0, $e);
		}

		$this->verifySignature($acquirerCertificate);
	}

	/**
	 * Verify the signature of the response
	 *
	 * @throws IDealException if the signature is invalid or we can't verify it
	 */
	private function verifySignature($acquirerCertificate)
	{
		// Check if the merchant certificate exists
		if ( !is_file($acquirerCertificate) ) {
			throw new \RuntimeException('The acquirer certificate doesn\'t exists. (' . $acquirerCertificate . ')');
		}

		try
		{
			// Convert SimpleXMLElement to DOMElement for verification
			$xml = new \DOMDocument();
			$xml->loadXML( $this->xml->asXml() );

			// Get the acquirer public key
			$publicKey = new \XMLSecurityKey(\XMLSecurityKey::RSA_SHA256, array('type' => 'public'));
			$publicKey->loadKey($acquirerCertificate, true);

			// Create and configure the DSig helper and get the signature
			$xmlDSigHelper = new \XMLSecurityDSig();
			$signature = $xmlDSigHelper->locateSignature($xml);

			// Check if we have a signature
			if (!$signature) {
				throw new IDealException('Failed to verify response signature: No signature found in response.');
			}

			// Canonicalize signed info so we can validate it
			$xmlDSigHelper->canonicalizeSignedInfo();

			// Validate and verify the signature
			if ( !$xmlDSigHelper->validateReference() ) {
				throw new IDealException('Failed to verify response signature: Reference not valid');
			}

			if ( !$xmlDSigHelper->verify($publicKey) ) {
				throw new IDealException('Failed to verify response signature: Signature invalid');
			}

		} catch (IDealException $e) {
			// Rethrow IDealExceptions
			throw $e;
		} catch (\Exception $e) {
			// Some verification methods throw exceptions by themself, we'll rethrow these
			throw new IDealException('Failed to verify response signature: ' . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * Get the type of response
	 *
	 * @return string
	 */
	public function getType()
	{
		return $this->xml->getName();
	}

	/**
	 * Get the moment this response was created on the server
	 *
	 * @return \DateTime|null
	 */
	public function getCreationDateTime()
	{
		$timestamp = (string)$this->xml->createDateTimestamp;

		if (strlen($timestamp) == 0) {
			return null;
		} else {
			return new \DateTime($timestamp);
		}
	}
}