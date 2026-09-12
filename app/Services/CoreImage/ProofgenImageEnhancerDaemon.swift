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
    private let basePath: String

    init(basePath: String = FileManager.default.currentDirectoryPath) {
        self.basePath = basePath

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
            guard highlightAmount <= 0 else {
                throw EnhancementError.invalidInput("Highlight adjustment supports values from -100 to 0. Positive highlight brightening is not supported.")
            }
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
            // blackPoint/whitePoint are percentages (0-100). calculatePercentileStats()
            // expects that same 0-100 scale and divides by 100 internally, so do NOT
            // pre-divide here. (A previous /100 made a 99% white point the 0.99th
            // percentile and a 2% black point the 0.02nd percentile.)
            let clipLow = blackPoint
            let clipHigh = whitePoint

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
        let logPath = "\(basePath)/storage/logs/laravel.log"
        func logDebug(_ message: String) {
            if let handle = FileHandle(forWritingAtPath: logPath) {
                handle.seekToEndOfFile()
                let timestamp = ISO8601DateFormatter().string(from: Date())
                let logEntry = "[\(timestamp)] local.DEBUG: [CoreImageDaemon] \(message)\n"
                handle.write(logEntry.data(using: .utf8)!)
                handle.closeFile()
            }
        }

        var result = image
        if stats.highValue > stats.lowValue {
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

            result = stretchedImage
        }

        // Apply shadow/highlight adjustments if specified
        // Only apply if we have actual adjustments to make
        let shouldApplyShadow = shadowAmount != 0
        let shouldApplyHighlight = highlightAmount < 0

        if shouldApplyShadow || shouldApplyHighlight {
            if let highlightShadowFilter = CIFilter(name: "CIHighlightShadowAdjust") {
                highlightShadowFilter.setValue(result, forKey: kCIInputImageKey)

                // Core Image's identity values are shadow=0 and highlight=1.
                // Preserve highlights during a shadow-only edit; negative shadow
                // values darken shadows, and negative highlights dampen highlights.
                let shadowValue = shadowAmount / 100.0
                let highlightValue = 1.0 + min(0, highlightAmount) / 100.0
                highlightShadowFilter.setValue(shadowValue, forKey: "inputShadowAmount")
                highlightShadowFilter.setValue(highlightValue, forKey: "inputHighlightAmount")
                highlightShadowFilter.setValue(shadowRadius, forKey: "inputRadius")

                if let adjustedImage = highlightShadowFilter.outputImage {
                    result = adjustedImage
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
                }
            }
        }

        return result
    }

    // MARK: - Helper Methods

    // Sample actual luminance values. CIAreaHistogram's normalized counts can
    // round to zero when rendered as RGBA8, making brightness a silent no-op.
    private func sampledHistogram(for image: CIImage) -> [Int] {
        let extent = image.extent
        let sampleScale = min(1.0, 500.0 / max(extent.width, extent.height))
        let width = max(1, Int(extent.width * sampleScale))
        let height = max(1, Int(extent.height * sampleScale))
        let normalized = image.transformed(by: CGAffineTransform(translationX: -extent.minX, y: -extent.minY))
        let sampled = normalized.transformed(by: CGAffineTransform(scaleX: sampleScale, y: sampleScale))
        var pixels = [UInt8](repeating: 0, count: width * height * 4)
        context.render(sampled, toBitmap: &pixels, rowBytes: width * 4,
                       bounds: CGRect(x: 0, y: 0, width: width, height: height),
                       format: .RGBA8, colorSpace: colorSpace)
        var histogram = [Int](repeating: 0, count: 256)
        for offset in stride(from: 0, to: pixels.count, by: 4) {
            let luminance = 0.299 * Double(pixels[offset])
                + 0.587 * Double(pixels[offset + 1]) + 0.114 * Double(pixels[offset + 2])
            histogram[min(255, max(0, Int(luminance.rounded())))] += 1
        }
        return histogram
    }

    private func calculateHistogramStats(for image: CIImage, targetBrightness: Int = 128, contrastThreshold: Int = 200) -> (brightness: Double, needsContrastBoost: Bool) {
        let histogram = sampledHistogram(for: image)
        let count = histogram.reduce(0, +)
        guard count > 0,
              let minimum = histogram.firstIndex(where: { $0 > 0 }),
              let maximum = histogram.lastIndex(where: { $0 > 0 }) else {
            return (brightness: 0, needsContrastBoost: false)
        }
        let sum = histogram.enumerated().reduce(0.0) { $0 + Double($1.offset * $1.element) }
        return (brightness: (Double(targetBrightness) - sum / Double(count)) / 255.0,
                needsContrastBoost: maximum - minimum < contrastThreshold)
    }

    private func calculatePercentileStats(for image: CIImage, lowPercentile: Double, highPercentile: Double) -> (lowValue: Double, highValue: Double) {
        let histogram = sampledHistogram(for: image)
        let count = histogram.reduce(0, +)
        guard count > 0 else { return (lowValue: 0, highValue: 1) }
        func value(at percentile: Double) -> Double {
            let target = max(1, Int(ceil(Double(count) * percentile / 100.0)))
            var cumulative = 0
            for (value, frequency) in histogram.enumerated() {
                cumulative += frequency
                if cumulative >= target { return Double(value) / 255.0 }
            }
            return 1
        }
        return (lowValue: value(at: lowPercentile), highValue: value(at: highPercentile))
    }

    // MARK: - Image I/O

    private func loadImage(from path: String) -> CIImage? {
        let url = URL(fileURLWithPath: path)

        // Load the image
        guard var image = CIImage(contentsOf: url) else {
            return nil
        }

        // Apply EXIF orientation if present
        if let orientation = image.properties[kCGImagePropertyOrientation as String] as? Int32,
           let cgOrientation = CGImagePropertyOrientation(rawValue: UInt32(orientation)) {
            image = image.oriented(cgOrientation)
        }

        return image
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
    private let enhancer: ProofgenImageEnhancer
    private var listener: NWListener?
    private let port: UInt16 = 9876
    private var lastRequestTime = Date()
    private var idleTimer: Timer?
    private let idleTimeoutMinutes: Int = 120 // 2 hours default
    private let basePath: String

    init(basePath: String) {
        self.basePath = basePath
        self.enhancer = ProofgenImageEnhancer(basePath: basePath)
    }

    func start() {
        let parameters = NWParameters.tcp
        parameters.allowLocalEndpointReuse = true

        // Log to Laravel log
        let logPath = "\(basePath)/storage/logs/laravel.log"
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
                self?.handleConnection(connection)
            }

            listener?.start(queue: .main)

            // Start idle timer
            startIdleTimer(log: log)

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
        // Update last request time
        lastRequestTime = Date()

        let logPath = "\(self.basePath)/storage/logs/laravel.log"
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

            let request = try JSONDecoder().decode(EnhancementRequest.self, from: data)

            let response = enhancer.enhance(request: request)

            let responseData = try JSONEncoder().encode(response)

            connection.send(content: responseData, completion: .contentProcessed { _ in
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

    private func startIdleTimer(log: @escaping (String) -> Void) {
        // Check for idle timeout configuration file
        let configPath = "\(self.basePath)/storage/core-image-idle-timeout.conf"
        var timeoutMinutes = idleTimeoutMinutes

        if let configData = try? String(contentsOfFile: configPath, encoding: .utf8),
           let minutes = Int(configData.trimmingCharacters(in: .whitespacesAndNewlines)) {
            timeoutMinutes = minutes
        }

        // If timeout is 0, don't set up the timer
        if timeoutMinutes == 0 {
            log("Idle timeout disabled")
            return
        }

        // Create timer that checks every minute
        idleTimer = Timer.scheduledTimer(withTimeInterval: 60.0, repeats: true) { [weak self] _ in
            self?.checkIdleTimeout(timeoutMinutes: timeoutMinutes, log: log)
        }
    }

    private func checkIdleTimeout(timeoutMinutes: Int, log: @escaping (String) -> Void) {
        let idleTime = Date().timeIntervalSince(lastRequestTime)
        let idleMinutes = Int(idleTime / 60.0)

        if idleMinutes >= timeoutMinutes {
            log("Idle timeout reached (\(idleMinutes) minutes). Shutting down daemon.")

            // Clean up PID file
            let pidPath = "\(self.basePath)/storage/core-image-daemon.pid"
            try? FileManager.default.removeItem(atPath: pidPath)

            // Cancel listener
            listener?.cancel()

            // Exit gracefully
            exit(0)
        }
    }
}

// MARK: - Main Entry Point

// Parse command line arguments
var basePath = FileManager.default.currentDirectoryPath
var isDaemon = false

for i in 0..<CommandLine.arguments.count {
    if CommandLine.arguments[i] == "--base-path" && i + 1 < CommandLine.arguments.count {
        basePath = CommandLine.arguments[i + 1]
    }
    if CommandLine.arguments[i] == "--daemon" {
        isDaemon = true
    }
}

// Check for command line arguments
if isDaemon {
    // Run as daemon
    let server = ImageEnhancerServer(basePath: basePath)
    server.start()
} else {
    // Run in stdin/stdout mode for backward compatibility
    print("READY")
    fflush(stdout)

    let enhancer = ProofgenImageEnhancer(basePath: basePath)

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
