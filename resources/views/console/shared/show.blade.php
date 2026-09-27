<x-console-layout :title="'Shared — '.$space->name">
    <x-console-panel>
        <x-page-header eyebrow="SHARED SPACE" title="{{ $space->name }}">
            <x-slot:subtitle>Everything agents have put in this folder — {{ $fileCount }} {{ Str::plural('file', $fileCount) }} total.</x-slot:subtitle>
            <x-slot:actions>
<div class="flex gap-2">
                <a href="{{ route('console.shared.edit', $space) }}"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-cream hover:border-amber"
                    style="border-radius: var(--radius-console)">acl</a>
                <a href="{{ route('console.shared.index') }}"
                    class="border border-border bg-void px-3 py-1.5 font-mono text-xs text-steel hover:border-amber hover:text-cream"
                    style="border-radius: var(--radius-console)">back</a>
            </div>
            </x-slot:actions>
        </x-page-header>

        @include('console.partials.dirtree', [
            'crumbs' => collect($breadcrumbs)->map(fn ($c) => [
                'name' => $c['name'],
                'url' => route('console.shared.show', ['shared' => $space, 'dir' => $c['dir']]),
            ]),
        ])

        @php
            $dirRows = collect($dirs)->map(fn ($d) => [
                'label' => $d.'/',
                'url' => route('console.shared.show', ['shared' => $space, 'dir' => $dir.$d.'/']),
            ]);
            if ($dir !== '') {
                $dirRows->prepend([
                    'label' => '..',
                    'url' => route('console.shared.show', ['shared' => $space, 'dir' => $breadcrumbs[count($breadcrumbs) - 2]['dir'] ?? '']),
                ]);
            }
        @endphp

        @if ($files->isEmpty() && $dirRows->isEmpty())
            <p class="mt-6 text-sm text-steel">{{ $fileCount === 0 ? 'No files in this space yet.' : 'Nothing at this level.' }}</p>
        @else
            <table class="mt-4 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">name</th>
                        <th class="py-2 pr-4">size</th>
                        <th class="py-2 pr-4">updated</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @include('console.partials.dirrows', ['rows' => $dirRows, 'colspan' => 3])
                    @foreach ($files as $file)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream" title="{{ $file->path }}">{{ Str::after($file->path, $dir) }}</td>
                            <td class="py-2 pr-4 text-steel">{{ $file->byte_size }} B</td>
                            <td class="py-2 pr-4 text-steel">{{ $file->updated_at->toDateTimeString() }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.shared.file', ['shared' => $space, 'path' => $file->path, 'dir' => $dir]) }}"
                                    class="text-amber underline decoration-border underline-offset-4">open</a>
                                <x-confirm-form action="{{ route('console.shared.file.destroy', $space) }}" method="DELETE" class="inline" confirm="Delete '{{ $file->path }}'? Any agent reading it next sees a miss.">
                                    <input type="hidden" name="path" value="{{ $file->path }}">
                                    <input type="hidden" name="dir" value="{{ $dir }}">
                                    <button type="submit" class="ml-3 text-steel underline decoration-border underline-offset-4 hover:text-danger">delete</button>
                                </x-confirm-form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        {{ $files->links() }}

        @error('path')
            <p class="mt-2 font-mono text-xs text-danger">{{ $message }}</p>
        @enderror
    </x-console-panel>
</x-console-layout>
