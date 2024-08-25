@extends('layouts.app')

@section('content_header')
    <h1>Dashboard</h1>
@stop

@section('content')
    <div class="card">
        <div class="card-body">
            <form id="upload-form" enctype="multipart/form-data" method="post" action="">
                @csrf
                <div class="form-group">
                    <label>Import type</label>
                    <x-adminlte-select2 name="type">
                        <option name="register_new_product">Register new product</option>
                        <option name="update_product">Update product</option>
                        <option name="update_price">Update price</option>
                        <option name="hide_product">Hide product</option>
                    </x-adminlte-select2>
                </div>
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <x-adminlte-input-file id="csvFile" name="fileCsv" label="CSV file"
                                placeholder="Choose a file..." />
                            <div class="progress mt-3">
                                <div id="progress-bar-1" class="progress-bar" role="progressbar" style="width: 0%;"
                                    aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                            </div>
                            <div id="message-1" class="mt-3"></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <x-adminlte-input-file id="zipFile" name="fileZip" label="ZIP file"
                                placeholder="Choose a file..." />
                            <div class="progress mt-3">
                                <div id="progress-bar-2" class="progress-bar" role="progressbar" style="width: 0%;"
                                    aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                            </div>
                            <div id="message-2" class="mt-3"></div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <x-adminlte-button type="submit" label="Submit" theme="success" icon="fas fa-check" />
                </div>
            </form>
        </div>
    </div>
@stop
@section('js')
    <script src="https://sdk.amazonaws.com/js/aws-sdk-2.1682.0.min.js"></script>
    <script>
        console.log("Hi, I'm using the Laravel-AdminLTE package!");

        AWS.config.update({
            accessKeyId: 'minioadmin',
            secretAccessKey: 'minioadmin',
            region: 'us-east-1'
        });

        const s3 = new AWS.S3({
            endpoint: 'http://localhost:9000',
            s3ForcePathStyle: true
        });

        function uploadFile(file, progressBarId, messageId) {
            const params = {
                Bucket: 'dev',
                Key: file.name,
                Body: file
            };

            const upload = s3.upload(params);

            upload.on('httpUploadProgress', function(event) {
                const percent = Math.round((event.loaded / event.total) * 100);
                const progressBar = document.getElementById(progressBarId);
                progressBar.style.width = percent + '%';
                progressBar.setAttribute('aria-valuenow', percent);
                progressBar.textContent = percent + '%';
            });

            upload.send(function(err, data) {
                if (err) {
                    document.getElementById(messageId).textContent = `Error: ${err.message}`;
                } else {
                    document.getElementById(messageId).textContent =
                        `Upload successful! File URL: ${data.Location}`;
                }
            });
        }

        document.getElementById('upload-form').addEventListener('submit', function(event) {
            event.preventDefault();

            const file1 = document.getElementById('csvFile').files[0];
            const file2 = document.getElementById('zipFile').files[0];

            if (!file1 || !file2) {
                alert('Please select CSV & ZIP files to upload.');
                return;
            }

            uploadFile(file1, 'progress-bar-1', 'message-1');
            uploadFile(file2, 'progress-bar-2', 'message-2');
        });
    </script>
@stop
