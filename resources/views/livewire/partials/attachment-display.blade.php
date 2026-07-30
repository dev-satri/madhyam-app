@props ([
    'attachments' => null,
    'label' => 'Attachments',
])

@php
    $items = [];
    if (is_string($attachments)) {
        $items = json_decode($attachments, true) ?: [];
    } elseif (is_array($attachments)) {
        $items = $attachments;
    }
    $items = array_values(array_filter($items, fn($a) => is_array($a) && !empty($a['url'])));
    if (empty($items)) return;

    $imageExts = ['jpg','jpeg','png','gif','webp','svg','bmp','ico'];
    $videoExts = ['mp4','mov','avi','webm','mkv','m4v','flv'];
    $audioExts = ['mp3','wav','ogg','aac','m4a','flac','wma'];
    $docExts   = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','csv','rtf','odt'];

    $images = []; $videos = []; $audios = []; $docs = []; $drives = []; $others = [];
    foreach ($items as $item) {
        $url  = $item['url'] ?? '';
        $ext  = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        $type = $item['type'] ?? '';
        if ($type === 'drive' || str_contains($url, 'drive.google.com')) {
            $drives[] = $item;
        } elseif (in_array($ext, $imageExts)) {
            $images[] = $item;
        } elseif (in_array($ext, $videoExts) || $type === 'video') {
            $videos[] = $item;
        } elseif (in_array($ext, $audioExts) || $type === 'audio') {
            $audios[] = $item;
        } elseif (in_array($ext, $docExts)) {
            $docs[] = $item;
        } else {
            $others[] = $item;
        }
    }

    $docIconMap = [
        'pdf'  => ['bg-red-50',  'fa-file-pdf text-red-500'],
        'doc'  => ['bg-blue-50', 'fa-file-word text-blue-500'],
        'docx' => ['bg-blue-50', 'fa-file-word text-blue-500'],
        'xls'  => ['bg-green-50','fa-file-excel text-green-500'],
        'xlsx' => ['bg-green-50','fa-file-excel text-green-500'],
        'csv'  => ['bg-green-50','fa-file-csv text-green-600'],
        'ppt'  => ['bg-orange-50','fa-file-powerpoint text-orange-500'],
        'pptx' => ['bg-orange-50','fa-file-powerpoint text-orange-500'],
        'txt'  => ['bg-gray-100','fa-file-alt text-gray-500'],
    ];

    $allItemsJson = json_encode(array_map(fn($item) => [
        'name' => $item['name'] ?? 'File',
        'url' => $item['url'] ?? '',
        'type' => $item['type'] ?? 'file',
    ], $items));
@endphp

<div x-data="attachmentPreview">
    <script type="application/json" data-attachment-files>{!! $allItemsJson !!}</script>
    <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-500 mb-2.5 flex items-center gap-1.5">
        <i class="fas fa-paperclip text-gray-400"></i> {{ $label }}
        <span
            class="inline-flex items-center justify-center min-w-[18px] h-[18px] rounded-full bg-gray-100 text-[10px] font-bold text-gray-500 px-1"
            >{{ count($items) }}</span
        >
    </p>

    {{-- Images --}}
    @if (count($images) > 0)
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 mb-3">
            @foreach ($images as $img)
                <button
                    type="button"
                    @click="openPreview({{ json_encode(['name' => $img['name'] ?? 'Image', 'url' => $img['url'] ?? '', 'type' => 'image']) }})"
                    class="group relative block aspect-square rounded-xl overflow-hidden border border-gray-200 hover:border-[var(--brand)] hover:shadow-md transition-all duration-200 bg-gray-50 text-left"
                >
                    <img
                        src="{{ $img['url'] }}"
                        alt="{{ $img['name'] ?? 'Image' }}"
                        class="w-full h-full object-cover"
                        loading="lazy"
                    />
                    <div
                        class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                    >
                        <div class="absolute bottom-0 left-0 right-0 p-2.5">
                            <p class="text-[11px] font-medium text-white truncate drop-shadow">{{ $img['name'] ?? 'Image' }}</p>
                        </div>
                    </div>
                    <div
                        class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                    >
                        <span
                            class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-white/90 shadow-sm backdrop-blur-sm"
                        >
                            <i class="fas fa-expand-alt text-[10px] text-gray-600"></i>
                        </span>
                    </div>
                </button>
            @endforeach
        </div>
    @endif

    {{-- Videos --}}
    @if (count($videos) > 0)
        <div class="space-y-2 mb-3">
            @foreach ($videos as $vid)
                <button
                    type="button"
                    @click="openPreview({{ json_encode(['name' => $vid['name'] ?? 'Video', 'url' => $vid['url'] ?? '', 'type' => 'video']) }})"
                    class="w-full text-left relative rounded-xl overflow-hidden border border-gray-200 bg-gray-900 hover:border-[var(--brand)] hover:shadow-md transition-all duration-200"
                >
                    <video
                        src="{{ $vid['url'] }}"
                        controls
                        preload="metadata"
                        class="w-full aspect-video object-cover pointer-events-none"
                    ></video>
                    <div
                        class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-2.5 pointer-events-none"
                    >
                        <p class="text-[11px] font-medium text-white truncate drop-shadow">{{ $vid['name'] ?? 'Video' }}</p>
                    </div>
                    <div
                        class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200"
                    >
                        <span
                            class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-white/90 shadow-sm backdrop-blur-sm"
                        >
                            <i class="fas fa-expand-alt text-xs text-gray-600"></i>
                        </span>
                    </div>
                </button>
            @endforeach
        </div>
    @endif

    {{-- Audio --}}
    @if (count($audios) > 0)
        <div class="space-y-2 mb-3">
            @foreach ($audios as $aud)
                <button
                    type="button"
                    @click="openPreview({{ json_encode(['name' => $aud['name'] ?? 'Audio', 'url' => $aud['url'] ?? '', 'type' => 'audio']) }})"
                    class="w-full text-left flex items-center gap-3 bg-gray-50/80 hover:bg-white rounded-xl px-3 py-2.5 border border-gray-100 hover:border-[var(--brand)] hover:shadow-sm transition-all duration-150 group"
                >
                    <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-pink-50 flex items-center justify-center">
                        <i class="fas fa-file-audio text-pink-500"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[13px] font-medium text-gray-700 truncate group-hover:text-[var(--brand)] transition-colors">{{ $aud['name'] ?? 'Audio' }}</p>
                    </div>
                    <i
                        class="fas fa-play-circle text-gray-300 group-hover:text-[var(--brand)] transition-colors flex-shrink-0"
                    ></i>
                </button>
            @endforeach
        </div>
    @endif

    {{-- Documents --}}
    @if (count($docs) > 0)
        <div class="space-y-1.5 mb-3">
            @foreach ($docs as $doc)
                @php
                    $dExt = strtolower(pathinfo($doc['url'] ?? '', PATHINFO_EXTENSION));
                    $dInfo = $docIconMap[$dExt] ?? ['bg-gray-100', 'fa-file text-gray-400'];
                @endphp
                <button
                    type="button"
                    @click="openPreview({{ json_encode(['name' => $doc['name'] ?? 'Document', 'url' => $doc['url'] ?? '', 'type' => 'document', 'ext' => $dExt]) }})"
                    class="w-full text-left flex items-center gap-3 bg-gray-50/80 hover:bg-white rounded-xl px-3 py-2.5 border border-gray-100 hover:border-gray-200 hover:shadow-sm transition-all duration-150 group"
                >
                    <div class="flex-shrink-0 w-9 h-9 rounded-lg {{ $dInfo[0] }} flex items-center justify-center">
                        <i class="fas {{ $dInfo[1] }} text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[13px] font-medium text-gray-700 group-hover:text-[var(--brand)] truncate transition-colors">{{ $doc['name'] ?? 'Document' }}</p>
                    </div>
                    <i
                        class="fas fa-eye text-[10px] text-gray-300 group-hover:text-[var(--brand)] transition-colors flex-shrink-0"
                    ></i>
                </button>
            @endforeach
        </div>
    @endif

    {{-- Drive Links --}}
    @if (count($drives) > 0)
        <div class="space-y-1.5 mb-3">
            @foreach ($drives as $drv)
                <a
                    href="{{ $drv['url'] }}"
                    target="_blank"
                    rel="noopener"
                    class="flex items-center gap-3 bg-gray-50/80 hover:bg-white rounded-xl px-3 py-2.5 border border-gray-100 hover:border-gray-200 hover:shadow-sm transition-all duration-150 group"
                >
                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center">
                        <i class="fab fa-google-drive text-blue-500 text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[13px] font-medium text-gray-700 group-hover:text-[var(--brand)] truncate transition-colors">{{ $drv['name'] ?? 'Drive Link' }}</p>
                        <p class="text-[11px] text-gray-400"><i class="fab fa-google-drive text-[9px]"></i> Google Drive</p>
                    </div>
                    <i
                        class="fas fa-external-link-alt text-[10px] text-gray-300 group-hover:text-[var(--brand)] transition-colors flex-shrink-0"
                    ></i>
                </a>
            @endforeach
        </div>
    @endif

    {{-- Other Files --}}
    @if (count($others) > 0)
        <div class="space-y-1.5">
            @foreach ($others as $oth)
                <button
                    type="button"
                    @click="openPreview({{ json_encode(['name' => $oth['name'] ?? 'File', 'url' => $oth['url'] ?? '', 'type' => $oth['type'] ?? 'file']) }})"
                    class="w-full text-left flex items-center gap-3 bg-gray-50/80 hover:bg-white rounded-xl px-3 py-2.5 border border-gray-100 hover:border-gray-200 hover:shadow-sm transition-all duration-150 group"
                >
                    <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-gray-100 flex items-center justify-center">
                        <i class="fas fa-file text-gray-400 text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[13px] font-medium text-gray-700 group-hover:text-[var(--brand)] truncate transition-colors">{{ $oth['name'] ?? 'File' }}</p>
                        <p class="text-[11px] text-gray-400 capitalize">{{ $oth['type'] ?? 'file' }}</p>
                    </div>
                    <i
                        class="fas fa-eye text-[10px] text-gray-300 group-hover:text-[var(--brand)] transition-colors flex-shrink-0"
                    ></i>
                </button>
            @endforeach
        </div>
    @endif

    {{-- ========== PREVIEW MODAL ========== --}}
    <template x-if="show">
        <div
            class="fixed inset-0 z-[90] flex items-center justify-center bg-black/80 backdrop-blur-sm p-4"
            @click.self="closePreview()"
            x-on:keydown.escape.window="closePreview()"
        >
            <div
                class="relative bg-white rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] flex flex-col overflow-hidden"
            >
                {{-- Header --}}
                <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 shrink-0">
                    <div class="flex items-center gap-3 min-w-0">
                        <div
                            class="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center"
                            :class="{
                                'bg-blue-50': currentFile.type === 'image',
                                'bg-purple-50': currentFile.type === 'video',
                                'bg-pink-50': currentFile.type === 'audio',
                                'bg-red-50': currentFile.ext === 'pdf',
                                'bg-gray-100':
                                    !['image', 'video', 'audio'].includes(currentFile.type) && currentFile.ext !== 'pdf'
                            }"
                        >
                            <i
                                class="fas text-sm"
                                :class="{
                                    'fa-image text-blue-500': currentFile.type === 'image',
                                    'fa-video text-purple-500': currentFile.type === 'video',
                                    'fa-file-audio text-pink-500': currentFile.type === 'audio',
                                    'fa-file-pdf text-red-500': currentFile.ext === 'pdf',
                                    'fa-file text-gray-400':
                                        !['image', 'video', 'audio'].includes(currentFile.type) &&
                                        currentFile.ext !== 'pdf'
                                }"
                            ></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-900 truncate" x-text="currentFile.name"></p>
                            <p class="text-[11px] text-gray-400 capitalize" x-text="currentFile.type"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <a
                            :href="currentFile.url"
                            download
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-[var(--brand)] rounded-lg hover:opacity-90 transition-opacity"
                        >
                            <i class="fas fa-download text-[10px]"></i> Download
                        </a>
                        <a
                            :href="currentFile.url"
                            target="_blank"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                        >
                            <i class="fas fa-external-link-alt text-[10px]"></i> Open
                        </a>
                        <button
                            @click="closePreview()"
                            class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-600 transition-colors"
                        >
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                {{-- Content --}}
                <div class="flex-1 overflow-auto p-5 flex items-center justify-center bg-gray-50 min-h-0">
                    {{-- Image Preview --}}
                    <template x-if="currentFile.type === 'image'">
                        <img
                            :src="currentFile.url"
                            :alt="currentFile.name"
                            class="max-w-full max-h-[70vh] object-contain rounded-lg shadow-sm"
                        />
                    </template>

                    {{-- Video Preview --}}
                    <template x-if="currentFile.type === 'video'">
                        <video
                            :src="currentFile.url"
                            controls
                            autoplay
                            class="max-w-full max-h-[70vh] rounded-lg shadow-sm"
                        ></video>
                    </template>

                    {{-- Audio Preview --}}
                    <template x-if="currentFile.type === 'audio'">
                        <div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-8 text-center">
                            <div
                                class="w-20 h-20 mx-auto mb-4 rounded-full bg-pink-50 flex items-center justify-center"
                            >
                                <i class="fas fa-music text-3xl text-pink-400"></i>
                            </div>
                            <p class="text-sm font-semibold text-gray-900 mb-4 truncate" x-text="currentFile.name"></p>
                            <audio :src="currentFile.url" controls autoplay class="w-full"></audio>
                        </div>
                    </template>

                    {{-- Document / PDF Preview --}}
                    <template x-if="currentFile.type === 'document' || currentFile.type === 'file'">
                        <div class="w-full h-full min-h-[500px]">
                            <iframe
                                :src="currentFile.url"
                                class="w-full h-full min-h-[500px] border-0 rounded-lg"
                                x-show="isPdf"
                            ></iframe>
                            <div x-show="!isPdf" class="flex flex-col items-center justify-center py-12">
                                <div class="w-20 h-20 rounded-full bg-gray-100 flex items-center justify-center mb-4">
                                    <i class="fas fa-file text-3xl text-gray-400"></i>
                                </div>
                                <p class="text-sm font-medium text-gray-700 mb-2" x-text="currentFile.name"></p>
                                <p class="text-xs text-gray-400 mb-4">Preview not available for this file type</p>
                                <a
                                    :href="currentFile.url"
                                    download
                                    class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white bg-[var(--brand)] rounded-lg hover:opacity-90 transition-opacity"
                                >
                                    <i class="fas fa-download text-xs"></i> Download to view
                                </a>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Footer with thumbnails --}}
                <template x-if="allFiles.length > 1">
                    <div class="border-t border-gray-100 px-5 py-3 bg-white shrink-0">
                        <div class="flex gap-2 overflow-x-auto pb-1">
                            <template x-for="(file, idx) in allFiles" :key="idx">
                                <button
                                    @click="
                                        currentFile = file;
                                        currentIndex = idx;
                                    "
                                    class="flex-shrink-0 w-14 h-14 rounded-lg overflow-hidden border-2 transition-all"
                                    :class="currentIndex === idx
                                        ? 'border-[var(--brand)] shadow-sm'
                                        : 'border-gray-200 hover:border-gray-300'"
                                >
                                    <template x-if="file.type === 'image'">
                                        <img :src="file.url" class="w-full h-full object-cover" />
                                    </template>
                                    <template x-if="file.type === 'video'">
                                        <div class="w-full h-full bg-purple-50 flex items-center justify-center">
                                            <i class="fas fa-video text-purple-400 text-xs"></i>
                                        </div>
                                    </template>
                                    <template x-if="file.type === 'audio'">
                                        <div class="w-full h-full bg-pink-50 flex items-center justify-center">
                                            <i class="fas fa-music text-pink-400 text-xs"></i>
                                        </div>
                                    </template>
                                    <template x-if="file.type === 'document' || file.type === 'file'">
                                        <div class="w-full h-full bg-gray-50 flex items-center justify-center">
                                            <i class="fas fa-file text-gray-400 text-xs"></i>
                                        </div>
                                    </template>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>
