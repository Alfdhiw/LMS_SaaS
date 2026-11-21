# Changelog

## Unreleased — M1 PR6

- Persetujuan pengguna untuk PR5 dicatat pada 21 November 2026; PR6 tetap menunggu review.
- Dashboard central menampilkan agregat tenant dan error provisioning yang dibatasi.
- Direktori tenant mendapat pencarian metadata sekolah dan pagination; audit platform memakai permission khusus,
  filter dan pagination, tanpa menampilkan metadata mentah atau data siswa.
- Metadata nama/timezone dapat diubah melalui action tenant dengan izin platform dan audit; slug, hostname, status,
  dan tenant archived tidak dapat diubah lewat action tersebut.
- Pengujian negatif, isolasi host dan concurrency lifecycle diperluas. Prosedur pilot dan gate produksi dicatat
  pada README; M1 belum dinyatakan siap untuk data nyata.
