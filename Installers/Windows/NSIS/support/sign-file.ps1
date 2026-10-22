param([Parameter(Mandatory=$true)][string]$FilePath)
$ErrorActionPreference='Stop'
try {
    $file=(Resolve-Path -LiteralPath $FilePath).Path
    $signature=Get-AuthenticodeSignature -LiteralPath $file
    if ($signature.Status -eq 'Valid') {
        if ($signature.SignerCertificate.PublicKey.Oid.Value -ne '1.2.840.113549.1.1.1') { throw "Smart App Control requires RSA signatures: $file" }
        exit 0
    }
    if ($signature.Status -ne 'NotSigned') { throw "Existing signature is invalid: $file ($($signature.Status))." }
    $signer=$env:VAULTCAST_SIGNING_SCRIPT
    if (!$signer -or !(Test-Path -LiteralPath $signer -PathType Leaf)) { throw 'A trusted signing script must be configured for release builds.' }
    # The maintainer's provider script accepts -FilePath and signs with a public CA certificate.
    # Certificate/HSM/service credentials stay in the build environment, never in the installer.
    & $signer -FilePath $file
    if (!$?) { throw "Signing failed: $file" }
    $signature=Get-AuthenticodeSignature -LiteralPath $file
    if ($signature.Status -ne 'Valid' -or !$signature.TimeStamperCertificate) { throw "A valid timestamped Authenticode signature is required: $file" }
    if ($signature.SignerCertificate.PublicKey.Oid.Value -ne '1.2.840.113549.1.1.1') { throw 'Smart App Control release signing requires an RSA certificate.' }
} catch {
    Write-Error $_
    exit 1
}
