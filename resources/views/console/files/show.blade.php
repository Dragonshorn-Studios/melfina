<x-console-layout :title="'Files — '.$agent->name" :agent="$agent">
    <x-console-panel>
        <x-page-header eyebrow="FILE TREE — {{ strtoupper($agent->name) }}" title="{{ $agent->name }}">
            <x-slot:subtitle>{{ $fileCount }} {{ Str::plural('file', $fileCount) }} in the tree.</x-slot:subtitle>
            <x-slot:actions>
<a href="{{ route('console.files.edit', ['agent' => $agent, 'dir' => $dir]) }}"
                class="border border-amber bg-amber px-3 py-1.5 font-mono text-xs font-semibold text-void hover:bg-amber-dim"
                style="border-radius: var(--radius-console)">new file</a>
            </x-slot:actions>
        </x-page-header>

        @include('console.partials.dirtree', [
            'crumbs' => collect($breadcrumbs)->map(fn ($c) => [
                'name' => $c['name'],
                'url' => route('console.files.show', ['agent' => $agent, 'dir' => $c['dir']]),
            ]),
        ])

        @php
            $dirRows = collect($dirs)->map(fn ($d) => [
                'label' => $d.'/',
                'url' => route('console.files.show', ['agent' => $agent, 'dir' => $dir.$d.'/']),
            ]);
            if ($dir !== '') {
                $dirRows->prepend([
                    'label' => '..',
                    'url' => route('console.files.show', ['agent' => $agent, 'dir' => $breadcrumbs[count($breadcrumbs) - 2]['dir'] ?? '']),
                ]);
            }
        @endphp

        @if (empty($files) && $dirRows->isEmpty())
            <p class="mt-6 text-sm text-steel">{{ $fileCount === 0 ? 'The tree is empty. The agent will write here over MCP, or you can seed a file now.' : 'Nothing at this level.' }}</p>
        @else
            <table class="mt-4 w-full border-collapse font-mono text-xs">
                <thead>
                    <tr class="border-b border-border text-left text-steel">
                        <th class="py-2 pr-4">name</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @include('console.partials.dirrows', ['rows' => $dirRows, 'colspan' => 1])
                    @foreach ($files as $path)
                        <tr class="border-b border-border/50">
                            <td class="py-2 pr-4 text-cream" title="{{ $path }}">{{ Str::after($path, $dir) }}</td>
                            <td class="py-2 text-right">
                                <a href="{{ route('console.files.edit', ['agent' => $agent, 'path' => $path, 'dir' => $dir]) }}" class="text-amber underline decoration-border underline-offset-4">open</a>
                                <x-confirm-form action="{{ route('console.files.destroy', $agent) }}" method="DELETE" class="inline" confirm="Delete '{{ $path }}'? The agent sees a miss on its next read.">
                                    <input type="hidden" name="path" value="{{ $path }}">
                                    <input type="hidden" name="dir" value="{{ $dir }}">
                                    <button type="submit" class="ml-3 text-steel underline decoration-border underline-offset-4 hover:text-danger">delete</button>
                                </x-confirm-form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @error('path')
            <p class="mt-2 font-mono text-xs text-danger">{{ $message }}</p>
        @enderror
    </x-console-panel>
</x-console-layout>
