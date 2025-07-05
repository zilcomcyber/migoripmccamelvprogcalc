// Map Management for County Project Tracking System

class MapManager {
    constructor() {
        this.map = null;
        this.markers = [];
        this.isMapVisible = false;
    }

    async showMap() {
        try {
            // Show the modal
            document.getElementById('mapModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            this.isMapVisible = true;

            // Initialize map if not already done
            if (!this.map) {
                await this.initializeMap();
            }

            // Load project markers
            await this.loadProjectMarkers();

            // Resizes map to ensure proper display
            setTimeout(() => {
                if (this.map) {
                    this.map.invalidateSize();
                }
            }, 100);

        } catch (error) {
            console.error('Error showing map:', error);
            // Check if Utils exists before calling
            if (typeof Utils !== 'undefined' && Utils.showNotification) {
                Utils.showNotification('Failed to load map', 'error');
            } else {
                alert('Failed to load map: ' + error.message);
            }
        }
    }

    async initializeMap() {
        try {
            // Check if Leaflet is loaded
            if (typeof L === 'undefined') {
                throw new Error('Leaflet library not loaded');
            }

            // Default center (Migori County, Kenya)
            const defaultLat = -1.0634;
            const defaultLng = 34.4738;

            // Initialize Leaflet map
            this.map = L.map('map').setView([defaultLat, defaultLng], 10);

            // Add OpenStreetMap tiles with error handling
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 18,
                errorTileUrl: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
            }).addTo(this.map);

            console.log('Map initialized successfully');;

            // Add scale control
            L.control.scale().addTo(this.map);

        } catch (error) {
            console.error('Error initializing map:', error);
            throw error;
        }
    }

    async loadProjectMarkers() {
        try {
            // Clear existing markers
            this.clearMarkers();

            // Get current filter parameters
            const urlParams = new URLSearchParams(window.location.search);
            
            // Fetch projects for map
            const response = await fetch(`api/projects.php?map=1&${urlParams.toString()}`);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Failed to load projects');
            }

            const projects = data.projects || [];
            const validProjects = projects.filter(project => 
                project.location_coordinates && 
                project.location_coordinates.includes(',')
            );

            if (validProjects.length === 0) {
                Utils.showNotification('No projects with location data found', 'warning');
                return;
            }

            // Add markers for each project
            validProjects.forEach(project => {
                this.addProjectMarker(project);
            });

            // Fit map to show all markers
            if (this.markers.length > 0) {
                const group = new L.featureGroup(this.markers);
                this.map.fitBounds(group.getBounds().pad(0.1));
            }

        } catch (error) {
            console.error('Error loading project markers:', error);
            Utils.showNotification('Failed to load project locations', 'error');
        }
    }

    addProjectMarker(project) {
        try {
            const [lat, lng] = project.location_coordinates.split(',').map(coord => parseFloat(coord.trim()));
            
            if (isNaN(lat) || isNaN(lng)) {
                console.warn(`Invalid coordinates for project ${project.id}: ${project.location_coordinates}`);
                return;
            }

            // Choose marker color based on status
            const markerColor = this.getMarkerColor(project.status);
            
            // Create custom icon with darker colors
            const statusColors = {
                'planning': '#92400e',     
                'ongoing': '#1e3a8a',      
                'completed': '#064e3b',    
                'suspended': '#c2410c',   
                'cancelled': '#991b1b'    
            };

            const color = statusColors[project.status] || '#1f2937';

            const markerIcon = new L.Icon({
                iconUrl: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(`
                    <svg width="25" height="41" viewBox="0 0 25 41" xmlns="http://www.w3.org/2000/svg">
                        <path fill="${color}" d="M12.5,0C5.6,0,0,5.6,0,12.5c0,6.9,12.5,28.5,12.5,28.5s12.5-21.6,12.5-28.5C25,5.6,19.4,0,12.5,0z"/>
                        <circle fill="${color}" cx="12.5" cy="12.5" r="3"/>
                    </svg>
                `)}`,
                iconSize: [25, 41],
                iconAnchor: [12.5, 41],
                popupAnchor: [0, -41]
            });

            // Create marker
            const marker = L.marker([lat, lng], { icon: markerIcon });

            // Create popup content
            const popupContent = this.createPopupContent(project);
            marker.bindPopup(popupContent, {
                maxWidth: 300,
                className: 'custom-popup'
            });

            // Add to map and markers array
            marker.addTo(this.map);
            this.markers.push(marker);

        } catch (error) {
            console.error(`Error adding marker for project ${project.id}:`, error);
        }
    }

    getMarkerColor(status) {
        const colors = {
            planning: 'bg-yellow-500',
            ongoing: 'bg-blue-500',
            completed: 'bg-green-500',
            suspended: 'bg-orange-500',
            cancelled: 'bg-red-500'
        };
        return colors[status] || 'bg-gray-500';
    }

    createPopupContent(project) {
        const statusBadge = this.getStatusBadgeClass(project.status);
        
        return `
            <div class="p-2 min-w-0">
                <div class="flex items-start justify-between mb-2">
                    <h4 class="font-semibold text-gray-900 text-sm leading-tight pr-2">
                        ${this.escapeHtml(project.project_name)}
                    </h4>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${statusBadge} whitespace-nowrap">
                        ${project.status.charAt(0).toUpperCase() + project.status.slice(1)}
                    </span>
                </div>
                
                <div class="space-y-1 text-xs text-gray-600">
                    <div>
                        <span class="font-medium">Department:</span> ${this.escapeHtml(project.department_name)}
                    </div>
                    <div>
                        <span class="font-medium">Location:</span> ${this.escapeHtml(project.ward_name)}, ${this.escapeHtml(project.sub_county_name)}
                    </div>
                    
                    ${project.progress_percentage > 0 ? `
                        <div class="mt-2">
                            <div class="flex items-center justify-between mb-1">
                                <span class="font-medium">Progress:</span>
                                <span>${project.progress_percentage}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-1.5">
                                <div class="h-1.5 rounded-full ${this.getProgressColor(project.progress_percentage)}" 
                                     style="width: ${project.progress_percentage}%"></div>
                            </div>
                        </div>
                    ` : ''}
                </div>
                
                <div class="flex space-x-2 mt-3">
                    <button onclick="showProjectDetails(${project.id}); mapManager.closeMap();" 
                            class="flex-1 bg-blue-600 text-white text-xs px-2 py-1 rounded hover:bg-blue-700 transition-colors">
                        <i class="fas fa-eye mr-1"></i>Details
                    </button>
                    <button onclick="showFeedbackForm(${project.id}); mapManager.closeMap();" 
                            class="flex-1 bg-green-600 text-white text-xs px-2 py-1 rounded hover:bg-green-700 transition-colors">
                        <i class="fas fa-comment mr-1"></i>Feedback
                    </button>
                </div>
            </div>
        `;
    }

    getStatusBadgeClass(status) {
        const classes = {
            planning: 'bg-yellow-100 text-yellow-800',
            ongoing: 'bg-blue-100 text-blue-800',
            completed: 'bg-green-100 text-green-800',
            suspended: 'bg-orange-100 text-orange-800',
            cancelled: 'bg-red-100 text-red-800'
        };
        return classes[status] || 'bg-gray-100 text-gray-800';
    }

    getProgressColor(percentage) {
        if (percentage >= 80) return 'bg-green-500';
        if (percentage >= 60) return 'bg-blue-500';
        if (percentage >= 40) return 'bg-yellow-500';
        if (percentage >= 20) return 'bg-orange-500';
        return 'bg-red-500';
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    clearMarkers() {
        this.markers.forEach(marker => {
            this.map.removeLayer(marker);
        });
        this.markers = [];
    }

    closeMap() {
        document.getElementById('mapModal').classList.add('hidden');
        document.body.style.overflow = 'auto';
        this.isMapVisible = false;
    }

    // Initialize map for project details modal
    initProjectDetailMap(project) {
        if (!project.location_coordinates) return;

        try {
            const [lat, lng] = project.location_coordinates.split(',').map(coord => parseFloat(coord.trim()));
            
            if (isNaN(lat) || isNaN(lng)) return;

            const mapContainer = document.getElementById('projectDetailMap');
            if (!mapContainer) return;

            // Clear any existing map
            mapContainer.innerHTML = '';

            // Create map
            const detailMap = L.map(mapContainer).setView([lat, lng], 15);

            // Add tiles
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(detailMap);

            // Add marker with darker color and no white ring
            const markerIcon = new L.Icon({
                iconUrl: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(`
                    <svg width="25" height="41" viewBox="0 0 25 41" xmlns="http://www.w3.org/2000/svg">
                        <path fill="#1e3a8a" d="M12.5,0C5.6,0,0,5.6,0,12.5c0,6.9,12.5,28.5,12.5,28.5s12.5-21.6,12.5-28.5C25,5.6,19.4,0,12.5,0z"/>
                        <circle fill="#1e3a8a" cx="12.5" cy="12.5" r="3"/>
                    </svg>
                `)}`,
                iconSize: [25, 41],
                iconAnchor: [12.5, 41],
                popupAnchor: [0, -41]
            });

            L.marker([lat, lng], { icon: markerIcon })
                .addTo(detailMap)
                .bindPopup(`<strong>${this.escapeHtml(project.project_name)}</strong><br>
                           ${this.escapeHtml(project.ward_name)}, ${this.escapeHtml(project.sub_county_name)}`)
                .openPopup();

        } catch (error) {
            console.error('Error initializing project detail map:', error);
        }
    }
}

// CSS for custom markers (inject into head)
const mapStyles = `
<style>
.custom-marker {
    background: transparent;
    border: none;
}

.custom-popup .leaflet-popup-content-wrapper {
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

.custom-popup .leaflet-popup-content {
    margin: 0;
    line-height: 1.4;
}

.custom-popup .leaflet-popup-tip {
    border-top-color: white;
}
</style>
`;

// Inject styles
document.head.insertAdjacentHTML('beforeend', mapStyles);
