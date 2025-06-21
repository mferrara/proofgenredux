#!/usr/bin/env swift

import Foundation
import CoreImage
import CoreGraphics
import AppKit

// MARK: - Data Structures

struct EnhancementRequest: Codable {
    let method: String
    let inputPath: String
    let outputPath: String
    let parameters: [String: Double]
}

struct EnhancementResponse: Codable {
    let success: Bool
    let outputPath: String?
    let error: String?
    let processingTime: Double
}

// MARK: - Core Image Enhancement Service

class ProofgenImageEnhancer {
    private let context: CIContext
    private let colorSpace = CGColorSpace(name: CGColorSpace.sRGB)!
    
    init() {
        // Create Metal-backed Core Image context for GPU acceleration
        let options: [CIContextOption: Any] = [
            .workingColorSpace: colorSpace,
            .outputColorSpace: colorSpace,
            .useSoftwareRenderer: false,
            .highQualityDownsample: true
        ]
        
        if let metalDevice = MTLCreateSystemDefaultDevice() {
            context = CIContext(mtlDevice: metalDevice, options: options)
        } else {
            // Fallback to default context
            context = CIContext(options: options)
        }
    }
    
    // MARK: - Main Enhancement Method
    
    func enhance(request: EnhancementRequest) -> EnhancementResponse {
        let startTime = Date()
        
        do {
            // Load image
            guard let image = loadImage(from: request.inputPath) else {
                throw EnhancementError.invalidInput("Failed to load image from \(request.inputPath)")
            }
            
            // Apply enhancement
            let enhanced = try applyEnhancement(to: image, method: request.method, parameters: request.parameters)
            
            // Save result
            try saveImage(enhanced, to: request.outputPath)
            
            let processingTime = Date().timeIntervalSince(startTime)
            return EnhancementResponse(success: true, outputPath: request.outputPath, error: nil, processingTime: processingTime)
            
        } catch {
            let processingTime = Date().timeIntervalSince(startTime)
            return EnhancementResponse(success: false, outputPath: nil, error: error.localizedDescription, processingTime: processingTime)
        }
    }
    
    // MARK: - Enhancement Methods
    
    private func applyEnhancement(to image: CIImage, method: String, parameters: [String: Double]) throws -> CIImage {
        switch method {
        case "basic_auto_levels":
            return applyBasicAutoLevels(to: image)
            
        case "percentile_clipping":
            let lowPercentile = parameters["percentile_low"] ?? 0.1
            let highPercentile = parameters["percentile_high"] ?? 99.9
            return applyPercentileClipping(to: image, lowPercentile: lowPercentile, highPercentile: highPercentile)
            
        case "percentile_with_curve":
            let lowPercentile = parameters["percentile_low"] ?? 0.1
            let highPercentile = parameters["percentile_high"] ?? 99.9
            return applyPercentileWithCurve(to: image, lowPercentile: lowPercentile, highPercentile: highPercentile)
            
        case "clahe":
            let clipLimit = parameters["clahe_clip_limit"] ?? 2.0
            let gridSize = Int(parameters["clahe_grid_size"] ?? 8)
            return applyCLAHE(to: image, clipLimit: clipLimit, gridSize: gridSize)
            
        case "smart_indoor":
            return applySmartIndoorEnhancement(to: image, parameters: parameters)
            
        default:
            throw EnhancementError.unknownMethod(method)
        }
    }
    
    // MARK: - Basic Auto Levels
    
    private func applyBasicAutoLevels(to image: CIImage) -> CIImage {
        // Use CIColorControls to auto-adjust levels
        guard let filter = CIFilter(name: "CIColorControls") else { return image }
        filter.setValue(image, forKey: kCIInputImageKey)
        
        // Calculate histogram to determine adjustments
        let histogram = calculateHistogramStats(for: image)
        
        // Apply brightness and contrast adjustments based on histogram
        filter.setValue(histogram.brightness, forKey: kCIInputBrightnessKey)
        filter.setValue(histogram.contrast, forKey: kCIInputContrastKey)
        filter.setValue(1.0, forKey: kCIInputSaturationKey)
        
        return filter.outputImage ?? image
    }
    
    // MARK: - Percentile Clipping
    
    private func applyPercentileClipping(to image: CIImage, lowPercentile: Double, highPercentile: Double) -> CIImage {
        // Calculate percentile values from histogram
        let stats = calculatePercentileStats(for: image, lowPercentile: lowPercentile, highPercentile: highPercentile)
        
        // Create tone curve that clips at percentiles
        let curveFilter = CIFilter(name: "CIToneCurve")!
        curveFilter.setValue(image, forKey: kCIInputImageKey)
        
        // Set up curve points for clipping
        let point0 = CIVector(x: 0, y: 0)
        let point1 = CIVector(x: CGFloat(stats.lowValue), y: 0)
        let point2 = CIVector(x: CGFloat(stats.highValue), y: 1)
        let point3 = CIVector(x: 1, y: 1)
        
        curveFilter.setValue(point0, forKey: "inputPoint0")
        curveFilter.setValue(point1, forKey: "inputPoint1")
        curveFilter.setValue(point2, forKey: "inputPoint2")
        curveFilter.setValue(point3, forKey: "inputPoint3")
        curveFilter.setValue(point3, forKey: "inputPoint4") // Required 5th point
        
        return curveFilter.outputImage ?? image
    }
    
    // MARK: - Percentile with S-Curve
    
    private func applyPercentileWithCurve(to image: CIImage, lowPercentile: Double, highPercentile: Double) -> CIImage {
        // First apply percentile clipping
        let clipped = applyPercentileClipping(to: image, lowPercentile: lowPercentile, highPercentile: highPercentile)
        
        // Then apply S-curve for contrast
        return applySCurve(to: clipped, strength: 3.0)
    }
    
    // MARK: - S-Curve
    
    private func applySCurve(to image: CIImage, strength: Double) -> CIImage {
        guard let filter = CIFilter(name: "CIToneCurve") else { return image }
        filter.setValue(image, forKey: kCIInputImageKey)
        
        // Create S-curve control points
        let offset: CGFloat = CGFloat(0.15 * (strength / 3.0))
        
        filter.setValue(CIVector(x: 0, y: 0), forKey: "inputPoint0")
        filter.setValue(CIVector(x: 0.25, y: 0.25 - offset), forKey: "inputPoint1")
        filter.setValue(CIVector(x: 0.5, y: 0.5), forKey: "inputPoint2")
        filter.setValue(CIVector(x: 0.75, y: 0.75 + offset), forKey: "inputPoint3")
        filter.setValue(CIVector(x: 1, y: 1), forKey: "inputPoint4")
        
        return filter.outputImage ?? image
    }
    
    // MARK: - CLAHE (Adaptive Histogram Equalization)
    
    private func applyCLAHE(to image: CIImage, clipLimit: Double, gridSize: Int) -> CIImage {
        // Core Image doesn't have direct CLAHE, but we can simulate with local contrast
        guard let filter = CIFilter(name: "CIHighlightShadowAdjust") else { return image }
        filter.setValue(image, forKey: kCIInputImageKey)
        
        // Adjust highlights and shadows for local contrast enhancement
        filter.setValue(0.3, forKey: "inputHighlightAmount")
        filter.setValue(0.3, forKey: "inputShadowAmount")
        filter.setValue(clipLimit, forKey: "inputRadius")
        
        // Apply additional local contrast enhancement
        if let localContrast = CIFilter(name: "CIUnsharpMask") {
            localContrast.setValue(filter.outputImage ?? image, forKey: kCIInputImageKey)
            localContrast.setValue(50.0, forKey: kCIInputRadiusKey)
            localContrast.setValue(1.5, forKey: kCIInputIntensityKey)
            return localContrast.outputImage ?? filter.outputImage ?? image
        }
        
        return filter.outputImage ?? image
    }
    
    // MARK: - Smart Indoor Enhancement
    
    private func applySmartIndoorEnhancement(to image: CIImage, parameters: [String: Double]) -> CIImage {
        var result = image
        
        // Step 1: Mild percentile clipping (0.1% - 99.8%)
        result = applyPercentileClipping(to: result, lowPercentile: 0.1, highPercentile: 99.8)
        
        // Step 2: Highlight/Shadow adjustment for indoor scenes
        if let highlightShadow = CIFilter(name: "CIHighlightShadowAdjust") {
            highlightShadow.setValue(result, forKey: kCIInputImageKey)
            highlightShadow.setValue(0.5, forKey: "inputHighlightAmount")
            highlightShadow.setValue(0.7, forKey: "inputShadowAmount")
            highlightShadow.setValue(30.0, forKey: "inputRadius")
            result = highlightShadow.outputImage ?? result
        }
        
        // Step 3: Warm tone adjustment (compensate for fluorescent)
        if let colorControls = CIFilter(name: "CIColorControls") {
            colorControls.setValue(result, forKey: kCIInputImageKey)
            colorControls.setValue(0.0, forKey: kCIInputBrightnessKey)
            colorControls.setValue(1.05, forKey: kCIInputContrastKey)
            colorControls.setValue(1.1, forKey: kCIInputSaturationKey)
            result = colorControls.outputImage ?? result
        }
        
        // Step 4: Reduce blue channel slightly
        if let colorMatrix = CIFilter(name: "CIColorMatrix") {
            colorMatrix.setValue(result, forKey: kCIInputImageKey)
            colorMatrix.setValue(CIVector(x: 1, y: 0, z: 0, w: 0), forKey: "inputRVector")
            colorMatrix.setValue(CIVector(x: 0, y: 1, z: 0, w: 0), forKey: "inputGVector")
            colorMatrix.setValue(CIVector(x: 0, y: 0, z: 0.98, w: 0), forKey: "inputBVector")
            colorMatrix.setValue(CIVector(x: 0, y: 0, z: 0, w: 1), forKey: "inputAVector")
            result = colorMatrix.outputImage ?? result
        }
        
        // Step 5: Gentle S-curve for contrast
        result = applySCurve(to: result, strength: 2.5)
        
        // Step 6: Slight sharpening
        if let sharpen = CIFilter(name: "CISharpenLuminance") {
            sharpen.setValue(result, forKey: kCIInputImageKey)
            sharpen.setValue(0.4, forKey: kCIInputSharpnessKey)
            sharpen.setValue(1.0, forKey: kCIInputRadiusKey)
            result = sharpen.outputImage ?? result
        }
        
        return result
    }
    
    // MARK: - Helper Methods
    
    private func calculateHistogramStats(for image: CIImage) -> (brightness: Double, contrast: Double) {
        // Create histogram filter
        guard let histogramFilter = CIFilter(name: "CIAreaHistogram") else {
            return (brightness: 0.0, contrast: 1.0)
        }
        
        histogramFilter.setValue(image, forKey: kCIInputImageKey)
        histogramFilter.setValue(CIVector(cgRect: image.extent), forKey: "inputExtent")
        histogramFilter.setValue(256, forKey: "inputCount")
        histogramFilter.setValue(1.0, forKey: "inputScale")
        
        guard let histogramImage = histogramFilter.outputImage else {
            return (brightness: 0.0, contrast: 1.0)
        }
        
        // Analyze histogram data
        var bitmap = [UInt8](repeating: 0, count: 256 * 4)
        context.render(histogramImage, toBitmap: &bitmap, rowBytes: 256 * 4, bounds: CGRect(x: 0, y: 0, width: 256, height: 1), format: .RGBA8, colorSpace: colorSpace)
        
        // Calculate mean and range
        var sum: Double = 0
        var count: Double = 0
        var minValue: Int = 255
        var maxValue: Int = 0
        
        for i in 0..<256 {
            let value = Double(bitmap[i * 4]) // Red channel contains histogram data
            if value > 0 {
                sum += Double(i) * value
                count += value
                if i < minValue { minValue = i }
                if i > maxValue { maxValue = i }
            }
        }
        
        let mean = count > 0 ? sum / count : 128.0
        let range = Double(maxValue - minValue)
        
        // Calculate adjustments
        let brightness = (128.0 - mean) / 255.0
        let contrast = range < 200 ? 1.2 : 1.0
        
        return (brightness: brightness, contrast: contrast)
    }
    
    private func calculatePercentileStats(for image: CIImage, lowPercentile: Double, highPercentile: Double) -> (lowValue: Double, highValue: Double) {
        // Create histogram
        guard let histogramFilter = CIFilter(name: "CIAreaHistogram") else {
            return (lowValue: 0.0, highValue: 1.0)
        }
        
        histogramFilter.setValue(image, forKey: kCIInputImageKey)
        histogramFilter.setValue(CIVector(cgRect: image.extent), forKey: "inputExtent")
        histogramFilter.setValue(256, forKey: "inputCount")
        histogramFilter.setValue(1.0, forKey: "inputScale")
        
        guard let histogramImage = histogramFilter.outputImage else {
            return (lowValue: 0.0, highValue: 1.0)
        }
        
        // Get histogram data
        var bitmap = [UInt8](repeating: 0, count: 256 * 4)
        context.render(histogramImage, toBitmap: &bitmap, rowBytes: 256 * 4, bounds: CGRect(x: 0, y: 0, width: 256, height: 1), format: .RGBA8, colorSpace: colorSpace)
        
        // Calculate cumulative distribution
        var cumulative = [Double](repeating: 0, count: 256)
        var total: Double = 0
        
        for i in 0..<256 {
            total += Double(bitmap[i * 4])
            cumulative[i] = total
        }
        
        // Find percentile values
        let lowTarget = total * lowPercentile / 100.0
        let highTarget = total * highPercentile / 100.0
        
        var lowIndex = 0
        var highIndex = 255
        
        for i in 0..<256 {
            if cumulative[i] >= lowTarget && lowIndex == 0 {
                lowIndex = i
            }
            if cumulative[i] >= highTarget {
                highIndex = i
                break
            }
        }
        
        return (lowValue: Double(lowIndex) / 255.0, highValue: Double(highIndex) / 255.0)
    }
    
    // MARK: - Image I/O
    
    private func loadImage(from path: String) -> CIImage? {
        let url = URL(fileURLWithPath: path)
        return CIImage(contentsOf: url)
    }
    
    private func saveImage(_ image: CIImage, to path: String) throws {
        let url = URL(fileURLWithPath: path)
        
        // Ensure output directory exists
        let directory = url.deletingLastPathComponent()
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        
        // Determine output format
        let pathExtension = url.pathExtension.lowercased()
        let outputFormat: CIFormat = (pathExtension == "png") ? .RGBA8 : .RGBA8
        
        // Render to file
        let colorSpace = CGColorSpace(name: CGColorSpace.sRGB)!
        
        if pathExtension == "jpg" || pathExtension == "jpeg" {
            // For JPEG, use higher-level API with quality control
            if let cgImage = context.createCGImage(image, from: image.extent) {
                let nsImage = NSImage(cgImage: cgImage, size: NSSize(width: cgImage.width, height: cgImage.height))
                if let tiffData = nsImage.tiffRepresentation,
                   let bitmap = NSBitmapImageRep(data: tiffData),
                   let jpegData = bitmap.representation(using: .jpeg, properties: [.compressionFactor: 1.0]) {
                    try jpegData.write(to: url)
                } else {
                    throw EnhancementError.saveFailed("Failed to create JPEG data")
                }
            } else {
                throw EnhancementError.saveFailed("Failed to create CGImage")
            }
        } else {
            // For other formats, use Core Image directly
            try context.writePNGRepresentation(of: image, to: url, format: outputFormat, colorSpace: colorSpace)
        }
    }
}

// MARK: - Error Types

enum EnhancementError: LocalizedError {
    case invalidInput(String)
    case unknownMethod(String)
    case processingFailed(String)
    case saveFailed(String)
    
    var errorDescription: String? {
        switch self {
        case .invalidInput(let message):
            return "Invalid input: \(message)"
        case .unknownMethod(let method):
            return "Unknown enhancement method: \(method)"
        case .processingFailed(let message):
            return "Processing failed: \(message)"
        case .saveFailed(let message):
            return "Save failed: \(message)"
        }
    }
}

// MARK: - Main Entry Point

class ImageEnhancerService {
    private let enhancer = ProofgenImageEnhancer()
    
    func run() {
        // Set up for line-buffered I/O
        setbuf(stdout, nil)
        setbuf(stderr, nil)
        
        // Send ready signal
        print("READY", terminator: "\n")
        fflush(stdout)
        
        // Process requests from stdin
        while let line = readLine() {
            if line == "EXIT" {
                break
            }
            
            guard let data = line.data(using: .utf8) else {
                sendError("Invalid input encoding")
                continue
            }
            
            do {
                let request = try JSONDecoder().decode(EnhancementRequest.self, from: data)
                let response = enhancer.enhance(request: request)
                sendResponse(response)
            } catch {
                sendError("Failed to decode request: \(error.localizedDescription)")
            }
        }
    }
    
    private func sendResponse(_ response: EnhancementResponse) {
        do {
            let data = try JSONEncoder().encode(response)
            if let json = String(data: data, encoding: .utf8) {
                print(json, terminator: "\n")
                fflush(stdout)
            }
        } catch {
            sendError("Failed to encode response: \(error.localizedDescription)")
        }
    }
    
    private func sendError(_ message: String) {
        let response = EnhancementResponse(success: false, outputPath: nil, error: message, processingTime: 0)
        sendResponse(response)
    }
}

// Run the service
let service = ImageEnhancerService()
service.run()