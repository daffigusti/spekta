@extends('legal.layout')

@section('title', 'Kebijakan Privasi')

@section('content')
  <h3>1. Data yang dikumpulkan</h3>
  <p>Data akun (nama, email, perusahaan), konten workspace yang Anda unggah, dan data pembayaran yang diproses Midtrans (Spekta tidak menyimpan nomor kartu).</p>
  <h3>2. Penggunaan data</h3>
  <p>Konten workspace dipakai hanya untuk menghasilkan dokumen Anda. Pemrosesan AI dilakukan melalui API resmi penyedia LLM (Anthropic/OpenAI) yang tidak menggunakan data API untuk melatih model. Data terisolasi per workspace.</p>
  <h3>3. Penyimpanan &amp; penghapusan</h3>
  <p>Data tersimpan selama akun aktif. Permintaan penghapusan akun dan data dapat diajukan via <a href="mailto:halo@spekta.id">halo@spekta.id</a>.</p>
  <h3>4. Kontak</h3>
  <p>Pertanyaan tentang dokumen ini: <a href="mailto:halo@spekta.id">halo@spekta.id</a>.</p>
@endsection

@section('crosslink')
  <a href="{{ route('legal.terms') }}">Syarat Layanan</a>
@endsection
