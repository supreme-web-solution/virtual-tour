<?php

namespace App\Services\StreetView;

use InvalidArgumentException;

/**
 * Writes the Photo Sphere (GPano) XMP block that Street View requires into a
 * JPEG, replacing any XMP the file already has.
 *
 * @see https://developers.google.com/streetview/spherical-metadata
 */
class PanoramaXmp
{
    protected const NAMESPACE_HEADER = "http://ns.adobe.com/xap/1.0/\0";

    public function embed(string $jpeg, int $width, int $height): string
    {
        if (strlen($jpeg) < 4 || ! str_starts_with($jpeg, "\xFF\xD8")) {
            throw new InvalidArgumentException('The image is not a JPEG.');
        }

        $position = 2;
        $kept = '';
        $length = strlen($jpeg);

        // Walk the header segments up to the image data, dropping old XMP.
        while ($position + 4 <= $length && $jpeg[$position] === "\xFF" && $jpeg[$position + 1] !== "\xDA") {
            $header = unpack('nsize', substr($jpeg, $position + 2, 2));

            if ($header === false) {
                throw new InvalidArgumentException('The JPEG is damaged.');
            }

            $size = $header['size'];
            $segment = substr($jpeg, $position, 2 + $size);
            $isXmp = $jpeg[$position + 1] === "\xE1" && str_starts_with(substr($segment, 4), self::NAMESPACE_HEADER);

            if (! $isXmp) {
                $kept .= $segment;
            }

            $position += 2 + $size;
        }

        $payload = self::NAMESPACE_HEADER.$this->packet($width, $height);

        return "\xFF\xD8".$kept."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, $position);
    }

    protected function packet(int $width, int $height): string
    {
        return '<?xpacket begin="'."\u{FEFF}".'" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            .'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            .'<rdf:Description rdf:about="" xmlns:GPano="http://ns.google.com/photos/1.0/panorama/">'
            .'<GPano:UsePanoramaViewer>True</GPano:UsePanoramaViewer>'
            .'<GPano:ProjectionType>equirectangular</GPano:ProjectionType>'
            ."<GPano:FullPanoWidthPixels>{$width}</GPano:FullPanoWidthPixels>"
            ."<GPano:FullPanoHeightPixels>{$height}</GPano:FullPanoHeightPixels>"
            ."<GPano:CroppedAreaImageWidthPixels>{$width}</GPano:CroppedAreaImageWidthPixels>"
            ."<GPano:CroppedAreaImageHeightPixels>{$height}</GPano:CroppedAreaImageHeightPixels>"
            .'<GPano:CroppedAreaLeftPixels>0</GPano:CroppedAreaLeftPixels>'
            .'<GPano:CroppedAreaTopPixels>0</GPano:CroppedAreaTopPixels>'
            .'<GPano:PoseHeadingDegrees>0</GPano:PoseHeadingDegrees>'
            .'</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }
}
