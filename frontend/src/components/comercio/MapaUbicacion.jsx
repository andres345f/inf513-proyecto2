import { useRef } from 'react'
import { CircleMarker, MapContainer, TileLayer, Tooltip, useMapEvents } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import './MapaUbicacion.css'

const CENTRO_SANTA_CRUZ = [-17.7833, -63.1821]

/** Mapa para marcar el punto de entrega. Muestra también el almacén de origen. */
export default function MapaUbicacion({ destino, origen, alElegir }) {
  const mapa = useRef(null)
  const marcarCentro = () => {
    const centro = mapa.current?.getCenter()
    if (centro) alElegir({ lat: centro.lat, lng: centro.lng })
  }
  return (
    <div className="mapa-ubicacion">
      <MapContainer center={destino ? [destino.lat, destino.lng] : CENTRO_SANTA_CRUZ} zoom={13} scrollWheelZoom
        ref={mapa} className="mapa-ubicacion__mapa" aria-label="Mapa para marcar tu ubicación de entrega">
        <TileLayer
          attribution='&copy; colaboradores de <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
          url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
        />
        {origen && (
          <CircleMarker center={[origen.lat, origen.lng]} radius={9} pathOptions={{ className: 'mapa-ubicacion__origen' }}>
            <Tooltip>{origen.nombre}</Tooltip>
          </CircleMarker>
        )}
        {destino && (
          <CircleMarker center={[destino.lat, destino.lng]} radius={11} pathOptions={{ className: 'mapa-ubicacion__destino' }}>
            <Tooltip permanent direction="top">Entrega aquí</Tooltip>
          </CircleMarker>
        )}
        <CapturaClic alElegir={alElegir} />
      </MapContainer>
      <button type="button" className="mapa-ubicacion__marcar" onClick={marcarCentro}>Marcar el centro visible del mapa</button>
      <p className="mapa-ubicacion__ayuda">Haz clic en el mapa o muévelo con el teclado y marca el centro visible.</p>
    </div>
  )
}

function CapturaClic({ alElegir }) {
  useMapEvents({ click: (evento) => alElegir({ lat: evento.latlng.lat, lng: evento.latlng.lng }) })
  return null
}
