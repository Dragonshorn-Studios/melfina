{{-- Directory rows for a file table: '..' to go up, then one row per
     subdirectory. Render inside the table's <tbody> before file rows.
     Expects: rows — list of ['label', 'url']; colspan — trailing cells
     the row spans after the name cell. --}}
@foreach ($rows as $row)
    <tr class="border-b border-border/50">
        <td class="py-2 pr-4">
            <a href="{{ $row['url'] }}" class="text-amber underline decoration-border underline-offset-4">{{ $row['label'] }}</a>
        </td>
        <td colspan="{{ $colspan }}" class="py-2 text-steel">{{ $row['label'] === '..' ? 'up' : 'dir' }}</td>
    </tr>
@endforeach
