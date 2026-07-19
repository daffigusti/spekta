@extends('legal.layout')

@section('title', 'Syarat Layanan')

@section('content')
  <h3>1. Layanan</h3>
  <p>Spekta adalah alat bantu presales berbasis AI untuk menghasilkan dokumen spesifikasi, estimasi man-days, dan RAB. Output AI bersifat draf: Anda bertanggung jawab me-review sebelum dipakai dalam kontrak atau proposal ke klien.</p>
  <h3>2. Akun &amp; workspace</h3>
  <p>Anda bertanggung jawab menjaga kredensial akun. Konten yang diunggah ke workspace (transkrip, dokumen, rate card) tetap milik Anda; Spekta hanya memprosesnya untuk menyediakan layanan.</p>
  <h3>3. Paket &amp; pembayaran</h3>
  <p>Kuota mengikuti paket berlangganan. Pembayaran diproses melalui Midtrans. Kuota bulanan di-reset tiap siklus tagihan; top-up berlaku sesuai ketentuan paket.</p>
  <h3>4. Batasan</h3>
  <p>Dilarang memakai layanan untuk konten melanggar hukum, atau mencoba mengakses workspace pihak lain. Layanan disediakan "sebagaimana adanya"; estimasi (termasuk rentang ±15%) bukan jaminan komersial.</p>
  <h3>5. Kontak</h3>
  <p>Pertanyaan tentang dokumen ini: <a href="mailto:halo@spekta.id">halo@spekta.id</a>.</p>
@endsection

@section('crosslink')
  <a href="{{ route('legal.privacy') }}">Kebijakan Privasi</a>
@endsection
