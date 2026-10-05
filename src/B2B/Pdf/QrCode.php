<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B\Pdf;

use InvalidArgumentException;

/**
 * Minimal dependency-free QR Code (model 2) encoder for printed production sheets: versions 1-10, alphanumeric or
 * byte mode, error correction L/M/Q/H, automatic mask choice by the standard penalty rules. It only encodes the
 * opaque canonical reference (ARASYA:Q1:...), which carries no business data. Verified by decoding with jsQR.
 */
final class QrCode
{
    private const ALNUM='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
    private const LEVEL_BITS=['L'=>1,'M'=>0,'Q'=>3,'H'=>2];
    /** version => level => [ec codewords per block, group 1 blocks, group 1 data codewords, group 2 blocks, group 2 data codewords] */
    private const BLOCKS=[
        1=>['L'=>[7,1,19,0,0],'M'=>[10,1,16,0,0],'Q'=>[13,1,13,0,0],'H'=>[17,1,9,0,0]],
        2=>['L'=>[10,1,34,0,0],'M'=>[16,1,28,0,0],'Q'=>[22,1,22,0,0],'H'=>[28,1,16,0,0]],
        3=>['L'=>[15,1,55,0,0],'M'=>[26,1,44,0,0],'Q'=>[18,2,17,0,0],'H'=>[22,2,13,0,0]],
        4=>['L'=>[20,1,80,0,0],'M'=>[18,2,32,0,0],'Q'=>[26,2,24,0,0],'H'=>[16,4,9,0,0]],
        5=>['L'=>[26,1,108,0,0],'M'=>[24,2,43,0,0],'Q'=>[18,2,15,2,16],'H'=>[22,2,11,2,12]],
        6=>['L'=>[18,2,68,0,0],'M'=>[16,4,27,0,0],'Q'=>[24,4,19,0,0],'H'=>[28,4,15,0,0]],
        7=>['L'=>[20,2,78,0,0],'M'=>[18,4,31,0,0],'Q'=>[18,2,14,4,15],'H'=>[26,4,13,1,14]],
        8=>['L'=>[24,2,97,0,0],'M'=>[22,2,38,2,39],'Q'=>[22,4,18,2,19],'H'=>[26,4,14,2,15]],
        9=>['L'=>[30,2,116,0,0],'M'=>[22,3,36,2,37],'Q'=>[20,4,16,4,17],'H'=>[24,4,12,4,13]],
        10=>['L'=>[18,2,68,2,69],'M'=>[26,4,43,1,44],'Q'=>[24,6,19,2,20],'H'=>[28,6,15,2,16]],
    ];
    private const ALIGNMENT=[1=>[],2=>[6,18],3=>[6,22],4=>[6,26],5=>[6,30],6=>[6,34],7=>[6,22,38],8=>[6,24,42],9=>[6,26,46],10=>[6,28,50]];

    /** @var list<list<bool>> */
    private array $modules=[];
    /** @var list<list<bool>> */
    private array $reserved=[];
    private int $size;

    /** @return list<list<bool>> dark modules, row-major, without quiet zone */
    public static function matrix(string $text,string $level='Q'): array
    {
        if(!isset(self::LEVEL_BITS[$level])) throw new InvalidArgumentException('Invalid error correction level.');
        $alnum=$text!=='' && strspn($text,self::ALNUM)===strlen($text);
        foreach(range(1,10) as $version) {
            $bits=self::data($text,$alnum,$version);
            [$ec,$b1,$d1,$b2,$d2]=self::BLOCKS[$version][$level];
            $capacity=($b1*$d1+$b2*$d2)*8;
            if(strlen($bits)<=$capacity) {
                $qr=new self();
                return $qr->build($version,$level,self::codewords($bits,$capacity,$version,$level));
            }
        }
        throw new InvalidArgumentException('Text is too long for this QR encoder.');
    }

    private static function data(string $text,bool $alnum,int $version): string
    {
        $count=strlen($text);
        if($alnum) {
            $bits='0010'.str_pad(decbin($count),$version<10?9:11,'0',STR_PAD_LEFT);
            for($i=0;$i<$count;$i+=2) {
                $a=strpos(self::ALNUM,$text[$i]);
                $bits.=$i+1<$count ? str_pad(decbin($a*45+strpos(self::ALNUM,$text[$i+1])),11,'0',STR_PAD_LEFT) : str_pad(decbin($a),6,'0',STR_PAD_LEFT);
            }
            return $bits;
        }
        $bits='0100'.str_pad(decbin($count),$version<10?8:16,'0',STR_PAD_LEFT);
        foreach(str_split($text) as $byte) $bits.=str_pad(decbin(ord($byte)),8,'0',STR_PAD_LEFT);
        return $bits;
    }

    /** Terminator, padding, block split, Reed-Solomon and interleaving. @return list<int> */
    private static function codewords(string $bits,int $capacity,int $version,string $level): array
    {
        $bits.=str_repeat('0',min(4,$capacity-strlen($bits)));
        if(strlen($bits)%8!==0) $bits.=str_repeat('0',8-strlen($bits)%8);
        $data=array_map('bindec',str_split($bits,8));
        for($pad=0;count($data)<$capacity/8;$pad^=1) $data[]=$pad===0?0xEC:0x11;
        [$ec,$b1,$d1,$b2,$d2]=self::BLOCKS[$version][$level];
        $blocks=[]; $offset=0;
        foreach([[$b1,$d1],[$b2,$d2]] as [$count,$length]) for($i=0;$i<$count;$i++) { $blocks[]=array_slice($data,$offset,$length); $offset+=$length; }
        $generator=self::generator($ec);
        $ecBlocks=array_map(static fn(array $block): array=>self::remainder($block,$generator,$ec),$blocks);
        $out=[];
        for($i=0;$i<max($d1,$d2);$i++) foreach($blocks as $block) if(isset($block[$i])) $out[]=$block[$i];
        for($i=0;$i<$ec;$i++) foreach($ecBlocks as $block) $out[]=$block[$i];
        return $out;
    }

    /** @return list<int> */
    private static function generator(int $degree): array
    {
        $poly=[1];
        for($i=0;$i<$degree;$i++) {
            $next=array_fill(0,count($poly)+1,0);
            foreach($poly as $j=>$coefficient) {
                $next[$j]^=$coefficient;
                $next[$j+1]^=self::multiply($coefficient,self::exp($i));
            }
            $poly=$next;
        }
        return $poly;
    }

    /** @return list<int> */
    private static function remainder(array $data,array $generator,int $degree): array
    {
        $buffer=array_merge($data,array_fill(0,$degree,0));
        for($i=0;$i<count($data);$i++) {
            $factor=$buffer[$i];
            if($factor===0) continue;
            foreach($generator as $j=>$g) $buffer[$i+$j]^=self::multiply($g,$factor);
        }
        return array_slice($buffer,count($data));
    }

    private static function exp(int $power): int
    {
        $value=1;
        for($i=0;$i<$power;$i++) { $value<<=1; if($value&0x100) $value^=0x11D; }
        return $value;
    }

    private static function multiply(int $a,int $b): int
    {
        $result=0;
        while($b>0) {
            if($b&1) $result^=$a;
            $a<<=1; if($a&0x100) $a^=0x11D;
            $b>>=1;
        }
        return $result;
    }

    /** @param list<int> $codewords */
    private function build(int $version,string $level,array $codewords): array
    {
        $this->size=17+4*$version;
        $this->modules=array_fill(0,$this->size,array_fill(0,$this->size,false));
        $this->reserved=$this->modules;
        foreach([[0,0],[$this->size-7,0],[0,$this->size-7]] as [$r,$c]) $this->finder($r,$c);
        for($i=8;$i<$this->size-8;$i++) { $this->set(6,$i,$i%2===0); $this->set($i,6,$i%2===0); }
        $positions=self::ALIGNMENT[$version];
        foreach($positions as $r) foreach($positions as $c) {
            if(($r===6 && $c===6) || ($r===6 && $c===end($positions)) || ($r===end($positions) && $c===6)) continue;
            for($dr=-2;$dr<=2;$dr++) for($dc=-2;$dc<=2;$dc++) $this->set($r+$dr,$c+$dc,max(abs($dr),abs($dc))!==1);
        }
        $this->set($this->size-8,8,true);
        $this->formatBits($level,0,true);
        if($version>=7) $this->versionBits($version);
        $bits='';
        foreach($codewords as $codeword) $bits.=str_pad(decbin($codeword),8,'0',STR_PAD_LEFT);
        $index=0; $up=true;
        for($col=$this->size-1;$col>0;$col-=2) {
            if($col===6) $col--;
            for($i=0;$i<$this->size;$i++) {
                $row=$up?$this->size-1-$i:$i;
                foreach([$col,$col-1] as $c) {
                    if($this->reserved[$row][$c]) continue;
                    $this->modules[$row][$c]=$index<strlen($bits) && $bits[$index]==='1';
                    $index++;
                }
            }
            $up=!$up;
        }
        $base=$this->modules; $best=null; $bestPenalty=PHP_INT_MAX;
        for($mask=0;$mask<8;$mask++) {
            $this->modules=$base;
            $this->mask($mask);
            $this->formatBits($level,$mask,false);
            $penalty=$this->penalty();
            if($penalty<$bestPenalty) { $bestPenalty=$penalty; $best=$this->modules; }
        }
        return $best;
    }

    private function finder(int $row,int $col): void
    {
        for($r=-1;$r<=7;$r++) for($c=-1;$c<=7;$c++) {
            $rr=$row+$r; $cc=$col+$c;
            if($rr<0 || $cc<0 || $rr>=$this->size || $cc>=$this->size) continue;
            $dark=$r>=0 && $r<=6 && $c>=0 && $c<=6 && ($r===0 || $r===6 || $c===0 || $c===6 || ($r>=2 && $r<=4 && $c>=2 && $c<=4));
            $this->set($rr,$cc,$dark);
        }
    }

    private function set(int $row,int $col,bool $dark): void
    {
        $this->modules[$row][$col]=$dark;
        $this->reserved[$row][$col]=true;
    }

    private function formatBits(string $level,int $mask,bool $reserveOnly): void
    {
        $data=(self::LEVEL_BITS[$level]<<3)|$mask;
        $rem=$data;
        for($i=0;$i<10;$i++) $rem=($rem<<1)^(($rem>>9)*0x537);
        $bits=(($data<<10)|$rem)^0x5412;
        $bit=static fn(int $i): bool=>$reserveOnly ? false : (($bits>>$i)&1)===1;
        for($i=0;$i<=5;$i++) $this->set($i,8,$bit($i));
        $this->set(7,8,$bit(6)); $this->set(8,8,$bit(7)); $this->set(8,7,$bit(8));
        for($i=9;$i<15;$i++) $this->set(8,14-$i,$bit($i));
        for($i=0;$i<8;$i++) $this->set(8,$this->size-1-$i,$bit($i));
        for($i=8;$i<15;$i++) $this->set($this->size-15+$i,8,$bit($i));
        $this->set($this->size-8,8,true);
    }

    private function versionBits(int $version): void
    {
        $rem=$version;
        for($i=0;$i<12;$i++) $rem=($rem<<1)^(($rem>>11)*0x1F25);
        $bits=($version<<12)|$rem;
        for($i=0;$i<18;$i++) {
            $dark=(($bits>>$i)&1)===1;
            $a=$this->size-11+$i%3; $b=intdiv($i,3);
            $this->set($a,$b,$dark); $this->set($b,$a,$dark);
        }
    }

    private function mask(int $mask): void
    {
        for($r=0;$r<$this->size;$r++) for($c=0;$c<$this->size;$c++) {
            if($this->reserved[$r][$c]) continue;
            $flip=match($mask) {
                0=>($r+$c)%2===0, 1=>$r%2===0, 2=>$c%3===0, 3=>($r+$c)%3===0,
                4=>(intdiv($r,2)+intdiv($c,3))%2===0, 5=>($r*$c)%2+($r*$c)%3===0,
                6=>(($r*$c)%2+($r*$c)%3)%2===0, 7=>(($r+$c)%2+($r*$c)%3)%2===0,
            };
            if($flip) $this->modules[$r][$c]=!$this->modules[$r][$c];
        }
    }

    private function penalty(): int
    {
        $n=$this->size; $m=$this->modules; $score=0;
        $lines=[];
        for($i=0;$i<$n;$i++) { $lines[]=$m[$i]; $lines[]=array_column($m,$i); }
        foreach($lines as $line) {
            $run=1;
            for($i=1;$i<=$n;$i++) {
                if($i<$n && $line[$i]===$line[$i-1]) { $run++; continue; }
                if($run>=5) $score+=$run-2;
                $run=1;
            }
            $text=implode('',array_map(static fn(bool $b): string=>$b?'1':'0',$line));
            $score+=40*(substr_count($text,'10111010000')+substr_count($text,'00001011101'));
        }
        for($r=0;$r<$n-1;$r++) for($c=0;$c<$n-1;$c++)
            if($m[$r][$c]===$m[$r][$c+1] && $m[$r][$c]===$m[$r+1][$c] && $m[$r][$c]===$m[$r+1][$c+1]) $score+=3;
        $dark=0;
        foreach($m as $row) $dark+=count(array_filter($row));
        $score+=intdiv(abs($dark*20-$n*$n*10),$n*$n)*10;
        return $score;
    }
}
