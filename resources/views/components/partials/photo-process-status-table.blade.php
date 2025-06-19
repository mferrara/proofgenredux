<div class="col-span-5">
    <flux:card>
        <flux:heading size="lg" class="mb-4">Processing Snapshot</flux:heading>

        <flux:table class="!text-gray-400">
            <thead>
            <tr>
                <th></th>
                <th class="text-center">Pending</th>
                <th class="text-center">Complete</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td class="h-8 font-medium text-lg">Imports</td>
                <td class="text-center">
                    @if(count($photos_pending_import))
                        <flux:badge color="amber">
                            {{ count($photos_pending_import) }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">
                    @if($photos_imported->count())
                        <flux:badge color="emerald">
                            {{ $photos_imported->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
            </tr>
            <tr>
                <td class="h-8 text-lg font-medium">Proofs</td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td class="h-8">&nbsp; - Generated</td>
                <td class="text-center">
                    @if($photos_pending_proofs->count())
                        <flux:badge color="amber">
                            {{ $photos_pending_proofs->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">
                    @if($photos_proofed->count())
                        <flux:badge color="emerald">
                            {{ $photos_proofed->count() }}
                        </flux:badge>
                    @endif
                </td>
            </tr>
            <tr>
                <td class="h-8">&nbsp;&nbsp;- Uploaded</td>
                <td class="text-center">
                    @if($photos_pending_proof_uploads->count())
                        <flux:badge color="amber">
                            {{ $photos_pending_proof_uploads->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">
                    @if($photos_proofs_uploaded->count())
                        <flux:badge color="emerald">
                            {{ $photos_proofs_uploaded->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
            </tr>
            <tr>
                <td class="h-8 font-medium text-lg">Web Images</td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td class="h-8">&nbsp; - Generated</td>
                <td class="text-center">
                    @if($photos_pending_web_images->count())
                        <flux:badge color="amber">
                            {{ $photos_pending_web_images->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">
                    @if($photos_web_images_generated->count())
                        <flux:badge color="emerald">
                            {{ $photos_web_images_generated->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
            </tr>
            <tr>
                <td class="h-8">&nbsp;&nbsp;- Uploaded</td>
                <td class="text-center">
                    @if($photos_pending_web_image_uploads->count())
                        <flux:badge color="amber">
                            {{ $photos_pending_web_image_uploads->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">
                    @if($photos_web_images_uploaded->count())
                        <flux:badge color="emerald">
                            {{ $photos_web_images_uploaded->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
            </tr>
            <tr>
                <td class="h-8 font-medium text-lg">Highres Images</td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td class="h-8">&nbsp; - Generated</td>
                <td class="text-center">
                    @if($photos_pending_highres_images->count())
                        <flux:badge color="amber">
                            {{ $photos_pending_highres_images->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">
                    @if($photos_highres_images_generated->count())
                        <flux:badge color="emerald">
                            {{ $photos_highres_images_generated->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
            </tr>
            <tr>
                <td class="h-8">&nbsp;&nbsp;- Uploaded</td>
                <td class="text-center">
                    @if($photos_pending_highres_image_uploads->count())
                        <flux:badge color="amber">
                            {{ $photos_pending_highres_image_uploads->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">
                    @if($photos_highres_images_uploaded->count())
                        <flux:badge color="emerald">
                            {{ $photos_highres_images_uploaded->count() }}
                        </flux:badge>
                    @else
                        -
                    @endif
                </td>
            </tr>
            </tbody>
        </flux:table>
    </flux:card>
</div>
