document.addEventListener('DOMContentLoaded', () => {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('pdfInput');
    const filePreview = document.getElementById('filePreview');
    const fileNameDisplay = document.getElementById('fileName');
    const fileSizeDisplay = document.getElementById('fileSize');
    const removeFileBtn = document.getElementById('removeFileBtn');
    // Drag and Drop styling
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
            dropZone.classList.add('dragover');
        }, false);
    });
    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => {
            dropZone.classList.remove('dragover');
        }, false);
    });
    // Handle dropped files
    dropZone.addEventListener('drop', (e) => {
        let dt = e.dataTransfer;
        let files = dt.files;
        if(files.length > 0) {
            if(files[0].type === "application/pdf") {
                fileInput.files = files;
                updateFilePreview();
            } else {
                alert("Please upload a valid PDF file.");
            }
        }
    });
    // Handle selected files via click
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            if(this.files[0].type !== "application/pdf") {
                alert("Please upload a valid PDF file.");
                this.value = ''; // Reset input
                hidePreview();
            } else {
                updateFilePreview();
                       }
        } else {
            hidePreview();
        }
    });
    // Remove file
    removeFileBtn.addEventListener('click', () => {
        fileInput.value = ''; // Clear the input
        hidePreview();
    });
    function updateFilePreview() {
        if(fileInput.files.length > 0) {
            const file = fileInput.files[0];
            fileNameDisplay.textContent = file.name;
            
            // Format size
                        let size = file.size;
            let sizeStr = "";
            if(size > 1024 * 1024) {
                sizeStr = (size / (1024 * 1024)).toFixed(2) + " MB";
            } else {
                sizeStr = (size / 1024).toFixed(2) + " KB";
            }
            fileSizeDisplay.textContent = sizeStr;
            
            dropZone.style.display = 'none';
            filePreview.classList.add('active');
        }
    }
    function hidePreview() {
        dropZone.style.display = 'block';
        filePreview.classList.remove('active');
    }
});
