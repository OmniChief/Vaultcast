# Committed licence texts (fallbacks)

`build.ps1` copies each bundled component's licence files out of the exact package
it downloaded. These committed copies are used only when a package ships no licence
file (WinSW, cacert.pem, VC++ redist, NSIS), or to add the full GPL/LGPL text that
a package only refers to. `SOURCE-OFFER.txt` is appended to the generated
`licenses/SOURCE.md`, which records the exact GPL versions and source URLs at build time.
Refresh these from upstream when the bundled major versions change.
