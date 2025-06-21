#!/usr/bin/env swift

import Foundation
import CoreImage
import CoreGraphics
import AppKit
import Network

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
            print("ProofgenImageEnhancer: Using Metal device for Core Image context")
        } else {
            // Fallback to default context
            context = CIContext(options: options)
            print("ProofgenImageEnhancer: Using default Core Image context (no Metal)")
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
        case "basic_auto_levels", "adjustable_auto_levels":
            let targetBrightness = parameters["auto_levels_target_brightness"] ?? 128.0
            let contrastThreshold = parameters["auto_levels_contrast_threshold"] ?? 200.0
            let contrastBoost = parameters["auto_levels_contrast_boost"] ?? 1.2
            let blackPoint = parameters["auto_levels_black_point"] ?? 0.0
            let whitePoint = parameters["auto_levels_white_point"] ?? 100.0
            return applyAdjustableAutoLevels(to: image, targetBrightness: targetBrightness,
                                           contrastThreshold: contrastThreshold, contrastBoost: contrastBoost,
                                           blackPoint: blackPoint, whitePoint: whitePoint)
            
        case "percentile_clipping", "advanced_tone_mapping":
            let lowPercentile = parameters["tone_mapping_percentile_low"] ?? 0.1
            let highPercentile = parameters["tone_mapping_percentile_high"] ?? 99.9
            let shadowAmount = parameters["tone_mapping_shadow_amount"] ?? 0.0
            let highlightAmount = parameters["tone_mapping_highlight_amount"] ?? 0.0
            let shadowRadius = parameters["tone_mapping_shadow_radius"] ?? 30.0
            let midtoneGamma = parameters["tone_mapping_midtone_gamma"] ?? 1.0
            return applyAdvancedToneMapping(to: image, lowPercentile: lowPercentile, highPercentile: highPercentile,
                                          shadowAmount: shadowAmount, highlightAmount: highlightAmount,
                                          shadowRadius: shadowRadius, midtoneGamma: midtoneGamma)
            
        default:
            throw EnhancementError.unknownMethod(method)
        }
    }
    
    // MARK: - Adjustable Auto Levels
    
    private func applyAdjustableAutoLevels(to image: CIImage, targetBrightness: Double,
                                         contrastThreshold: Double, contrastBoost: Double,
                                         blackPoint: Double, whitePoint: Double) -> CIImage {
        var processedImage = image
        
        // Apply black/white point levels adjustment if specified
        if blackPoint > 0 || whitePoint < 100 {
            let clipLow = blackPoint / 100.0
            let clipHigh = whitePoint / 100.0
            
            // Calculate percentile values for levels adjustment
            let stats = calculatePercentileStats(for: processedImage, lowPercentile: clipLow, highPercentile: clipHigh)
            
            // Apply levels adjustment instead of hard clipping
            if let levelsFilter = CIFilter(name: "CIColorPolynomial") {
                levelsFilter.setValue(processedImage, forKey: kCIInputImageKey)
                
                // Calculate polynomial coefficients to remap the range
                // We want to map [lowValue, highValue] to [0, 1]
                let range = stats.highValue - stats.lowValue
                if range > 0 {
                    let scale = 1.0 / range
                    let offset = -stats.lowValue * scale
                    
                    // Apply the same polynomial to R, G, and B channels
                    let coefficients = CIVector(x: CGFloat(offset), y: CGFloat(scale), z: 0, w: 0)
                    levelsFilter.setValue(coefficients, forKey: "inputRedCoefficients")
                    levelsFilter.setValue(coefficients, forKey: "inputGreenCoefficients")
                    levelsFilter.setValue(coefficients, forKey: "inputBlueCoefficients")
                    levelsFilter.setValue(CIVector(x: 0, y: 1, z: 0, w: 0), forKey: "inputAlphaCoefficients")
                    
                    processedImage = levelsFilter.outputImage ?? processedImage
                }
            }
        }
        
        // Calculate histogram to determine adjustments
        let histogram = calculateHistogramStats(for: processedImage, targetBrightness: Int(targetBrightness),
                                               contrastThreshold: Int(contrastThreshold))
        
        // Use CIColorControls to apply adjustments
        guard let filter = CIFilter(name: "CIColorControls") else { return processedImage }
        filter.setValue(processedImage, forKey: kCIInputImageKey)
        
        // Apply brightness adjustment
        filter.setValue(histogram.brightness, forKey: kCIInputBrightnessKey)
        
        // Apply contrast adjustment with configurable boost
        let finalContrast = histogram.needsContrastBoost ? contrastBoost : 1.0
        filter.setValue(finalContrast, forKey: kCIInputContrastKey)
        
        // Keep saturation at 1.0 (no change)
        filter.setValue(1.0, forKey: kCIInputSaturationKey)
        
        return filter.outputImage ?? processedImage
    }
    
    // MARK: - Advanced Tone Mapping
    
    private func applyAdvancedToneMapping(to image: CIImage, lowPercentile: Double, highPercentile: Double,
                                        shadowAmount: Double, highlightAmount: Double,
                                        shadowRadius: Double, midtoneGamma: Double) -> CIImage {
        // Calculate percentile values from histogram
        let stats = calculatePercentileStats(for: image, lowPercentile: lowPercentile, highPercentile: highPercentile)
        
        // Log to Laravel log
        let logPath = "/Users/mikeferrara/Herd/proofgenredux/storage/logs/laravel.log"
        func logDebug(_ message: String) {
            if let handle = FileHandle(forWritingAtPath: logPath) {
                handle.seekToEndOfFile()
                let timestamp = ISO8601DateFormatter().string(from: Date())
                let logEntry = "[\(timestamp)] local.DEBUG: [CoreImageDaemon] \(message)\n"
                handle.write(logEntry.data(using: .utf8)!)
                handle.closeFile()
            }
        }
        
        logDebug("Applying advanced tone mapping with lowValue: \(stats.lowValue), highValue: \(stats.highValue)")
        
        // Use CIColorClamp to clip values outside the percentile range
        guard let clampFilter = CIFilter(name: "CIColorClamp") else { return image }
        clampFilter.setValue(image, forKey: kCIInputImageKey)
        clampFilter.setValue(CIVector(x: CGFloat(stats.lowValue), y: CGFloat(stats.lowValue), z: CGFloat(stats.lowValue), w: 0), forKey: "inputMinComponents")
        clampFilter.setValue(CIVector(x: CGFloat(stats.highValue), y: CGFloat(stats.highValue), z: CGFloat(stats.highValue), w: 1), forKey: "inputMaxComponents")
        
        guard let clampedImage = clampFilter.outputImage else { 
            logDebug("ERROR: Color clamp failed")
            return image 
        }
        
        // Now apply linear stretching using an affine transform on colors
        let scale = 1.0 / (stats.highValue - stats.lowValue)
        let offset = -stats.lowValue * scale
        
        logDebug("Applying linear stretch - scale: \(scale), offset: \(offset)")
        
        guard let matrixFilter = CIFilter(name: "CIColorMatrix") else { return clampedImage }
        matrixFilter.setValue(clampedImage, forKey: kCIInputImageKey)
        
        // Scale RGB channels
        matrixFilter.setValue(CIVector(x: CGFloat(scale), y: 0, z: 0, w: 0), forKey: "inputRVector")
        matrixFilter.setValue(CIVector(x: 0, y: CGFloat(scale), z: 0, w: 0), forKey: "inputGVector")
        matrixFilter.setValue(CIVector(x: 0, y: 0, z: CGFloat(scale), w: 0), forKey: "inputBVector")
        matrixFilter.setValue(CIVector(x: 0, y: 0, z: 0, w: 1), forKey: "inputAVector")
        matrixFilter.setValue(CIVector(x: CGFloat(offset), y: CGFloat(offset), z: CGFloat(offset), w: 0), forKey: "inputBiasVector")
        
        guard let stretchedImage = matrixFilter.outputImage else {
            logDebug("ERROR: Color matrix failed, returning clamped image")
            return clampedImage
        }
        
        var result = stretchedImage
        
        // Apply shadow/highlight adjustments if specified
        // Only apply if we have actual adjustments to make
        let shouldApplyShadow = shadowAmount > 0
        let shouldApplyHighlight = highlightAmount < 0
        
        if shouldApplyShadow || shouldApplyHighlight {
            if let highlightShadowFilter = CIFilter(name: "CIHighlightShadowAdjust") {
                highlightShadowFilter.setValue(result, forKey: kCIInputImageKey)
                
                // According to Apple docs and testing:
                // inputShadowAmount: 0 to 1 (amount of shadow brightening)
                // inputHighlightAmount: 0 to 1 (amount of highlight dampening)
                // inputRadius: The radius of the effect (in pixels)
                
                // Our UI uses -100 to +100 where:
                // Shadows: positive values brighten shadows (matches filter expectation)
                // Highlights: negative values darken highlights (matches filter expectation)
                
                // Map shadow amount: UI 0-100 -> filter 0-1 (only positive values brighten)
                let shadowValue = shadowAmount > 0 ? shadowAmount / 100.0 : 0
                
                // Map highlight amount: UI -100-0 -> filter 0-1 (only negative UI values darken)
                let highlightValue = highlightAmount < 0 ? -highlightAmount / 100.0 : 0
                
                // Set the values as NSNumber objects as required by Core Image
                highlightShadowFilter.setValue(NSNumber(value: shadowValue), forKey: "inputShadowAmount")
                highlightShadowFilter.setValue(NSNumber(value: highlightValue), forKey: "inputHighlightAmount")
                highlightShadowFilter.setValue(NSNumber(value: shadowRadius), forKey: "inputRadius")
                
                // Log if we're applying zero values (which might trigger unexpected behavior)
                if shadowValue == 0 && highlightValue == 0 {
                    logDebug("WARNING: Both shadow and highlight are 0, filter might still apply some effect")
                }
                
                logDebug("Shadow/Highlight adjustment - UI values: shadow=\(shadowAmount), highlight=\(highlightAmount)")
                logDebug("Shadow/Highlight adjustment - Filter values: shadow=\(shadowValue), highlight=\(highlightValue), radius=\(shadowRadius)")
                
                if let adjustedImage = highlightShadowFilter.outputImage {
                    result = adjustedImage
                    logDebug("Successfully applied shadow/highlight adjustments")
                } else {
                    logDebug("ERROR: Shadow/highlight filter failed to produce output")
                }
            } else {
                logDebug("ERROR: Could not create CIHighlightShadowAdjust filter")
            }
        }
        
        // Apply midtone gamma correction if not 1.0
        if midtoneGamma != 1.0 {
            if let gammaFilter = CIFilter(name: "CIGammaAdjust") {
                gammaFilter.setValue(result, forKey: kCIInputImageKey)
                gammaFilter.setValue(midtoneGamma, forKey: "inputPower")
                
                if let gammaAdjusted = gammaFilter.outputImage {
                    result = gammaAdjusted
                    logDebug("Applied gamma correction: \(midtoneGamma)")
                }
            }
        }
        
        logDebug("Advanced tone mapping applied successfully")
        return result
    }
    
    // MARK: - Helper Methods
    
    private func calculateHistogramStats(for image: CIImage, targetBrightness: Int = 128, contrastThreshold: Int = 200) -> (brightness: Double, needsContrastBoost: Bool) {
        // Create histogram filter
        guard let histogramFilter = CIFilter(name: "CIAreaHistogram") else {
            return (brightness: 0.0, needsContrastBoost: false)
        }
        
        histogramFilter.setValue(image, forKey: kCIInputImageKey)
        histogramFilter.setValue(CIVector(cgRect: image.extent), forKey: "inputExtent")
        histogramFilter.setValue(256, forKey: "inputCount")
        histogramFilter.setValue(1.0, forKey: "inputScale")
        
        guard let histogramImage = histogramFilter.outputImage else {
            return (brightness: 0.0, needsContrastBoost: false)
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
        
        let mean = count > 0 ? sum / count : Double(targetBrightness)
        let range = Double(maxValue - minValue)
        
        // Calculate adjustments
        let brightness = (Double(targetBrightness) - mean) / 255.0
        let needsContrastBoost = range < Double(contrastThreshold)
        
        return (brightness: brightness, needsContrastBoost: needsContrastBoost)
    }
    
    private func calculatePercentileStats(for image: CIImage, lowPercentile: Double, highPercentile: Double) -> (lowValue: Double, highValue: Double) {
        // Log to Laravel log
        let logPath = "/Users/mikeferrara/Herd/proofgenredux/storage/logs/laravel.log"
        func logDebug(_ message: String) {
            if let handle = FileHandle(forWritingAtPath: logPath) {
                handle.seekToEndOfFile()
                let timestamp = ISO8601DateFormatter().string(from: Date())
                let logEntry = "[\(timestamp)] local.DEBUG: [CoreImageDaemon] \(message)\n"
                handle.write(logEntry.data(using: .utf8)!)
                handle.closeFile()
            }
        }
        
        logDebug("calculatePercentileStats called with percentiles: \(lowPercentile)% - \(highPercentile)%")
        logDebug("Input image extent: \(image.extent)")
        
        // Try manual histogram calculation
        let extent = image.extent
        let width = Int(extent.width)
        let height = Int(extent.height)
        
        // Sample the image at a lower resolution for performance
        let sampleScale = min(1.0, 500.0 / max(Double(width), Double(height)))
        let sampleWidth = Int(Double(width) * sampleScale)
        let sampleHeight = Int(Double(height) * sampleScale)
        
        logDebug("Sampling image at \(sampleWidth)x\(sampleHeight) (scale: \(sampleScale))")
        
        // Create bitmap to read pixel data
        var pixelData = [UInt8](repeating: 0, count: sampleWidth * sampleHeight * 4)
        let scaledImage = image.transformed(by: CGAffineTransform(scaleX: sampleScale, y: sampleScale))
        
        context.render(scaledImage, toBitmap: &pixelData, rowBytes: sampleWidth * 4, 
                      bounds: CGRect(x: 0, y: 0, width: sampleWidth, height: sampleHeight), 
                      format: .RGBA8, colorSpace: colorSpace)
        
        // Build histogram manually
        var histogram = [Int](repeating: 0, count: 256)
        var pixelCount = 0
        
        for y in 0..<sampleHeight {
            for x in 0..<sampleWidth {
                let offset = (y * sampleWidth + x) * 4
                // Convert to grayscale using standard weights
                let r = Double(pixelData[offset])
                let g = Double(pixelData[offset + 1])
                let b = Double(pixelData[offset + 2])
                let gray = Int(0.299 * r + 0.587 * g + 0.114 * b)
                histogram[min(255, max(0, gray))] += 1
                pixelCount += 1
            }
        }
        
        logDebug("Manual histogram built with \(pixelCount) pixels")
        
        // Calculate cumulative distribution
        var cumulative = [Double](repeating: 0, count: 256)
        var total: Double = 0
        
        for i in 0..<256 {
            total += Double(histogram[i])
            cumulative[i] = total
        }
        
        if total == 0 {
            logDebug("ERROR: Manual histogram is empty")
            return (lowValue: 0.0, highValue: 1.0)
        }
        
        logDebug("Manual histogram total: \(Int(total))")
        
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
        
        let result = (lowValue: Double(lowIndex) / 255.0, highValue: Double(highIndex) / 255.0)
        logDebug("Percentile stats - lowIndex: \(lowIndex), highIndex: \(highIndex), lowValue: \(result.lowValue), highValue: \(result.highValue)")
        return result
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

// MARK: - TCP Server

class ImageEnhancerServer {
    private let enhancer = ProofgenImageEnhancer()
    private var listener: NWListener?
    private let port: UInt16 = 9876
    
    func start() {
        let parameters = NWParameters.tcp
        parameters.allowLocalEndpointReuse = true
        
        // Log to Laravel log
        let logPath = "/Users/mikeferrara/Herd/proofgenredux/storage/logs/laravel.log"
        func log(_ message: String) {
            if let handle = FileHandle(forWritingAtPath: logPath) {
                handle.seekToEndOfFile()
                let timestamp = ISO8601DateFormatter().string(from: Date())
                let logEntry = "[\(timestamp)] local.INFO: [CoreImageDaemon] \(message)\n"
                handle.write(logEntry.data(using: .utf8)!)
                handle.closeFile()
            }
            print(message)
        }
        
        do {
            listener = try NWListener(using: parameters, on: NWEndpoint.Port(integerLiteral: port))
            
            listener?.newConnectionHandler = { [weak self] connection in
                log("New connection received")
                self?.handleConnection(connection)
            }
            
            listener?.start(queue: .main)
            log("Server listening on port \(port)")
            
            // Keep the program running
            RunLoop.main.run()
            
        } catch {
            log("Failed to start server: \(error)")
            exit(1)
        }
    }
    
    private func handleConnection(_ connection: NWConnection) {
        connection.start(queue: .main)
        
        // Read data
        connection.receive(minimumIncompleteLength: 1, maximumLength: 65536) { [weak self] data, _, _, error in
            if let data = data, !data.isEmpty {
                self?.processRequest(data: data, connection: connection)
            } else if let error = error {
                print("Connection error: \(error)")
                connection.cancel()
            }
        }
    }
    
    private func processRequest(data: Data, connection: NWConnection) {
        let logPath = "/Users/mikeferrara/Herd/proofgenredux/storage/logs/laravel.log"
        func log(_ message: String) {
            if let handle = FileHandle(forWritingAtPath: logPath) {
                handle.seekToEndOfFile()
                let timestamp = ISO8601DateFormatter().string(from: Date())
                let logEntry = "[\(timestamp)] local.INFO: [CoreImageDaemon] \(message)\n"
                handle.write(logEntry.data(using: .utf8)!)
                handle.closeFile()
            }
        }
        
        do {
            let jsonString = String(data: data, encoding: .utf8) ?? "Invalid UTF8"
            log("Received request: \(jsonString)")
            
            let request = try JSONDecoder().decode(EnhancementRequest.self, from: data)
            log("Decoded request - method: \(request.method), input: \(request.inputPath), output: \(request.outputPath)")
            
            let response = enhancer.enhance(request: request)
            log("Enhancement complete - success: \(response.success), time: \(response.processingTime)s")
            
            let responseData = try JSONEncoder().encode(response)
            log("Sending response...")
            
            connection.send(content: responseData, completion: .contentProcessed { _ in
                log("Response sent, closing connection")
                connection.cancel()
            })
            
        } catch {
            log("Error processing request: \(error)")
            
            let errorResponse = EnhancementResponse(
                success: false,
                outputPath: nil,
                error: error.localizedDescription,
                processingTime: 0
            )
            
            if let responseData = try? JSONEncoder().encode(errorResponse) {
                connection.send(content: responseData, completion: .contentProcessed { _ in
                    connection.cancel()
                })
            } else {
                connection.cancel()
            }
        }
    }
}

// MARK: - Main Entry Point

// Check for command line arguments
if CommandLine.arguments.contains("--daemon") {
    // Run as daemon
    let server = ImageEnhancerServer()
    server.start()
} else {
    // Run in stdin/stdout mode for backward compatibility
    print("READY")
    fflush(stdout)
    
    let enhancer = ProofgenImageEnhancer()
    
    while let line = readLine() {
        if line == "EXIT" {
            break
        }
        
        guard let data = line.data(using: .utf8) else {
            continue
        }
        
        do {
            let request = try JSONDecoder().decode(EnhancementRequest.self, from: data)
            let response = enhancer.enhance(request: request)
            let responseData = try JSONEncoder().encode(response)
            if let json = String(data: responseData, encoding: .utf8) {
                print(json)
                fflush(stdout)
            }
        } catch {
            let errorResponse = EnhancementResponse(
                success: false,
                outputPath: nil,
                error: error.localizedDescription,
                processingTime: 0
            )
            if let responseData = try? JSONEncoder().encode(errorResponse),
               let json = String(data: responseData, encoding: .utf8) {
                print(json)
                fflush(stdout)
            }
        }
    }
}