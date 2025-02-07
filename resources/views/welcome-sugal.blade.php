<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procesar Excel</title>
</head>
<body>
    <h1>Subir y Procesar Archivo Excel</h1>
    @if (isset($status))
        <div class="alert alert-success" role="alert">
            <p>{{ $status }}</p>
            @if (!empty($transportistas))
                <ul>
                    @foreach ($transportistas as $item)
                        <li>{{ $item ?? 'Nombre no disponible' }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif


    <form action="{{ route('procesar') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <input type="file" name="excel_file" accept=".xlsx,.xls" required>
        <button type="submit">Procesar</button>
    </form>
</body>
</html>
