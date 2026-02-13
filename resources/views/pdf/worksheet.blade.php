<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>製造記録</title>
    <style>
        @page {
            size: A4;
            margin: 0;
        }
        body {
            font-family: 'ipag', 'ipagp', 'sans-serif';
            margin: 0;
            padding: 0;
            background-color: #fff;
        }
        .page {
            position: relative;
            width: 210mm;
            height: 297mm;
            page-break-after: always;
            overflow: hidden;
        }
        .template-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: contain;
            z-index: 1;
        }
        .annotation {
            position: absolute;
            z-index: 2;
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12pt;
            color: #000;
            line-height: 1.2;
        }
        .annotation-photo {
            object-fit: contain;
        }
    </style>
</head>
<body>
    @foreach($pages as $page)
        <div class="page">
            <img src="{{ $page['template_base64'] }}" class="template-image">

            @foreach($page['annotations'] as $annotation)
                <div class="annotation" style="
                    left: {{ $annotation['x'] }}%;
                    top: {{ $annotation['y'] }}%;
                    @if($annotation['type'] === 'photo')
                        width: {{ $annotation['width'] }}%;
                        height: {{ $annotation['height'] }}%;
                    @endif
                ">
                    @if($annotation['type'] === 'photo' && isset($annotation['photo_base64']))
                        <img src="{{ $annotation['photo_base64'] }}" class="annotation-photo" style="width: 100%; height: 100%;">
                    @else
                        {{ $annotation['value'] }}
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>
