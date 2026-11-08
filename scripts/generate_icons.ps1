Add-Type -AssemblyName System.Drawing

function Generate-GoLivreurIcon {
    param (
        [int]$Size,
        [string]$OutputPath
    )

    $bmp = New-Object System.Drawing.Bitmap $Size, $Size
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.Clear([System.Drawing.Color]::Transparent)

    $scale = $Size / 512.0

    # Gradient background
    $rect = New-Object System.Drawing.Rectangle 0, 0, $Size, $Size
    $c1 = [System.Drawing.ColorTranslator]::FromHtml('#FFB732')
    $c2 = [System.Drawing.ColorTranslator]::FromHtml('#F4A623')
    $brush = New-Object System.Drawing.Drawing2D.LinearGradientBrush $rect, $c1, $c2, 45.0

    # Rounded rectangle path
    $margin = 16.0 * $scale
    $width = $Size - ($margin * 2)
    $radius = 110.0 * $scale
    $d = $radius * 2

    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $path.AddArc($margin, $margin, $d, $d, 180, 90)
    $path.AddArc($margin + $width - $d, $margin, $d, $d, 270, 90)
    $path.AddArc($margin + $width - $d, $margin + $width - $d, $d, $d, 0, 90)
    $path.AddArc($margin, $margin + $width - $d, $d, $d, 90, 90)
    $path.CloseFigure()

    $g.FillPath($brush, $path)

    # Motorcycle White Graphics
    $whitePen16 = New-Object System.Drawing.Pen([System.Drawing.Color]::White, (18.0 * $scale))
    $whitePen16.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
    $whitePen16.EndCap = [System.Drawing.Drawing2D.LineCap]::Round

    $whitePen12 = New-Object System.Drawing.Pen([System.Drawing.Color]::White, (14.0 * $scale))
    $whitePen12.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
    $whitePen12.EndCap = [System.Drawing.Drawing2D.LineCap]::Round

    $whiteBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::White)

    # Wheels (outer ring + center hub)
    $wheelR = 48.0 * $scale
    # Rear wheel (165, 315)
    $rearX = 165.0 * $scale
    $rearY = 315.0 * $scale
    $g.DrawEllipse($whitePen16, ($rearX - $wheelR), ($rearY - $wheelR), ($wheelR * 2), ($wheelR * 2))
    $g.FillEllipse($whiteBrush, ($rearX - 12.0 * $scale), ($rearY - 12.0 * $scale), (24.0 * $scale), (24.0 * $scale))

    # Front wheel (347, 315)
    $frontX = 347.0 * $scale
    $frontY = 315.0 * $scale
    $g.DrawEllipse($whitePen16, ($frontX - $wheelR), ($frontY - $wheelR), ($wheelR * 2), ($wheelR * 2))
    $g.FillEllipse($whiteBrush, ($frontX - 12.0 * $scale), ($frontY - 12.0 * $scale), (24.0 * $scale), (24.0 * $scale))

    # Swingarm: rear hub to motor pivot (245, 280)
    $pivotX = 245.0 * $scale
    $pivotY = 280.0 * $scale
    $g.DrawLine($whitePen16, $rearX, $rearY, $pivotX, $pivotY)

    # Front fork: front hub to handlebar head (300, 185)
    $headX = 300.0 * $scale
    $headY = 185.0 * $scale
    $g.DrawLine($whitePen16, $frontX, $frontY, $headX, $headY)

    # Handlebars
    $g.DrawLine($whitePen12, ($headX - 15.0 * $scale), ($headY - 5.0 * $scale), ($headX + 25.0 * $scale), ($headY - 20.0 * $scale))

    # Frame body (pivot to head)
    $g.DrawLine($whitePen16, $pivotX, $pivotY, $headX, $headY)

    # Fuel tank (curved body)
    $tankPath = New-Object System.Drawing.Drawing2D.GraphicsPath
    $tankPath.AddEllipse(($headX - 65.0 * $scale), ($headY + 5.0 * $scale), (70.0 * $scale), (38.0 * $scale))
    $g.FillPath($whiteBrush, $tankPath)

    # Seat (sleek cushion from 170 to 240)
    $seatPen = New-Object System.Drawing.Pen([System.Drawing.Color]::White, (20.0 * $scale))
    $seatPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
    $seatPen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
    $g.DrawLine($seatPen, ($rearX + 5.0 * $scale), ($pivotY - 45.0 * $scale), ($pivotX + 5.0 * $scale), ($pivotY - 42.0 * $scale))

    # Engine block accent (filled center)
    $g.FillEllipse($whiteBrush, ($pivotX - 25.0 * $scale), ($pivotY - 22.0 * $scale), (48.0 * $scale), (42.0 * $scale))

    # Headlight
    $g.FillEllipse($whiteBrush, ($headX + 22.0 * $scale), ($headY + 10.0 * $scale), (16.0 * $scale), (22.0 * $scale))

    # Top case / delivery box on rear rack
    $boxRect = New-Object System.Drawing.RectangleF (($rearX - 35.0 * $scale), ($pivotY - 95.0 * $scale), (45.0 * $scale), (42.0 * $scale))
    $boxPath = New-Object System.Drawing.Drawing2D.GraphicsPath
    $boxRadius = 8.0 * $scale
    $boxD = $boxRadius * 2
    $boxPath.AddArc($boxRect.X, $boxRect.Y, $boxD, $boxD, 180, 90)
    $boxPath.AddArc(($boxRect.Right - $boxD), $boxRect.Y, $boxD, $boxD, 270, 90)
    $boxPath.AddArc(($boxRect.Right - $boxD), ($boxRect.Bottom - $boxD), $boxD, $boxD, 0, 90)
    $boxPath.AddArc($boxRect.X, ($boxRect.Bottom - $boxD), $boxD, $boxD, 90, 90)
    $boxPath.CloseFigure()
    $g.FillPath($whiteBrush, $boxPath)

    # Clean save
    $dir = [System.IO.Path]::GetDirectoryName($OutputPath)
    if (![System.IO.Directory]::Exists($dir)) {
        [System.IO.Directory]::CreateDirectory($dir) | Out-Null
    }
    $bmp.Save($OutputPath, [System.Drawing.Imaging.ImageFormat]::Png)

    $whitePen16.Dispose()
    $whitePen12.Dispose()
    $whiteBrush.Dispose()
    $seatPen.Dispose()
    $brush.Dispose()
    $path.Dispose()
    $g.Dispose()
    $bmp.Dispose()
}

# Run generation for all mipmap densities across all 3 Flutter apps
$densities = @{
    'mipmap-mdpi'    = 48
    'mipmap-hdpi'    = 72
    'mipmap-xhdpi'   = 96
    'mipmap-xxhdpi'  = 144
    'mipmap-xxxhdpi' = 192
}

$apps = @(
    'c:\Projets\golivreur\mobile\apps\golivreur_client',
    'c:\Projets\golivreur\mobile\apps\golivreur_business',
    'c:\Projets\golivreur\mobile\apps\golivreur_driver'
)

foreach ($app in $apps) {
    Write-Host "Generating icons for $app..."
    foreach ($entry in $densities.GetEnumerator()) {
        $folder = $entry.Key
        $size = $entry.Value
        $targetFile = Join-Path $app "android\app\src\main\res\$folder\ic_launcher.png"
        Generate-GoLivreurIcon -Size $size -OutputPath $targetFile
        Write-Host "  -> $folder ($size x $size): OK"
    }
}

# Also generate a 512x512 preview and artifact
$previewPath = 'C:\Users\PC\.gemini\antigravity\brain\8e6f5f51-0cd9-4744-9a5d-f90d5c4627f7\golivreur_app_icon.png'
Generate-GoLivreurIcon -Size 512 -OutputPath $previewPath
Write-Host "Generated preview 512x512 icon at $previewPath"

