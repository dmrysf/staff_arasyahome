<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

/** Romanian (default) and Turkish labels shared by the proposal and production PDFs, plus display number formats. */
final class ProjectLabels
{
    public const TYPES=[
        'ro'=>[
            'property'=>['apartment'=>'Apartament','house'=>'Vilă / casă','hotel'=>'Hotel','hospital'=>'Spital / clinică','restaurant'=>'Restaurant',
                'office'=>'Birouri','commercial'=>'Clădire comercială','other'=>'Alt tip de proiect'],
            'zone'=>['floor'=>'Etaj','zone'=>'Zonă'],
            'opening'=>['window'=>'Fereastră','door'=>'Ușă','balcony_door'=>'Ușă de balcon','wall'=>'Perete','other'=>'Alt gol'],
            'treatment'=>['sheer'=>'Perdea','drapery'=>'Draperie','blackout'=>'Draperie blackout','rail'=>'Șină / galerie','accessory'=>'Accesoriu','other'=>'Alt produs'],
            'kind'=>['curtain'=>'Perdea','drapery'=>'Draperie','other'=>'Alt produs'],
            'layout'=>['single'=>'un panou','pair'=>'pereche','left'=>'stânga','right'=>'dreapta'],
            'mounting'=>['ceiling'=>'montaj tavan','wall'=>'montaj perete','recess'=>'montaj în nișă'],
        ],
        'tr'=>[
            'property'=>['apartment'=>'Daire','house'=>'Villa / müstakil ev','hotel'=>'Otel','hospital'=>'Hastane / klinik','restaurant'=>'Restoran',
                'office'=>'Ofis','commercial'=>'Ticari bina','other'=>'Diğer proje türü'],
            'zone'=>['floor'=>'Kat','zone'=>'Bölge'],
            'opening'=>['window'=>'Pencere','door'=>'Kapı','balcony_door'=>'Balkon kapısı','wall'=>'Duvar','other'=>'Diğer açıklık'],
            'treatment'=>['sheer'=>'Tül','drapery'=>'Fon perde','blackout'=>'Karartma perde','rail'=>'Ray / korniş','accessory'=>'Aksesuar','other'=>'Diğer ürün'],
            'kind'=>['curtain'=>'Tül','drapery'=>'Fon perde','other'=>'Diğer ürün'],
            'layout'=>['single'=>'tek kanat','pair'=>'çift kanat','left'=>'sol','right'=>'sağ'],
            'mounting'=>['ceiling'=>'tavan montajı','wall'=>'duvar montajı','recess'=>'niş içi montaj'],
        ],
    ];

    public static function language(mixed $value): string { return $value==='tr'?'tr':'ro'; }

    /** 180.000 -> 180, 180.500 -> 180,5 (centimetres or metres; never used for money). */
    public static function measure(?string $value): string
    {
        if($value===null) return '—';
        [$int,$frac]=array_pad(explode('.',$value),2,'');
        $frac=rtrim($frac,'0');
        return ltrim($int,'0')===''?($frac===''?'0':'0,'.$frac):ltrim($int,'0').($frac===''?'':','.$frac);
    }

    /** 1234.50 -> 1.234,50 from the exact decimal string. */
    public static function money(?string $value): string
    {
        if($value===null) return '—';
        [$int,$frac]=array_pad(explode('.',$value),2,'00');
        return number_format((int)$int,0,',','.').','.str_pad($frac,2,'0');
    }

    public static function dimensions(?string $width,?string $height): string
    {
        if($width===null && $height===null) return '—';
        return self::measure($width).' × '.self::measure($height).' cm';
    }
}
